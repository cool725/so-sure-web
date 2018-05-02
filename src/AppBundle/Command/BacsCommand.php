<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\SequenceService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

class BacsCommand extends BaseCommand
{
    use DateTrait;
    const S3_BUCKET = 'admin.so-sure.com';

    protected function configure()
    {
        $this
            ->setName('sosure:bacs')
            ->setDescription('Run a bacs export')
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'show debug output'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_NONE,
                'Processing date'
            )
            ->addOption(
                'skip-sftp',
                null,
                InputOption::VALUE_NONE,
                'Skip sftp upload'
            )
            ->addOption(
                'skip-s3',
                null,
                InputOption::VALUE_NONE,
                'Skip s3 upload'
            )
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'Skip sending email confirmation'
            )
            ->addArgument(
                'prefix',
                InputArgument::REQUIRED,
                'Prefix'
            )
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = $input->getOption('date');
        $skipEmail = true === $input->getOption('skip-email');
        $debug = $input->getOption('debug');
        $prefix = $input->getArgument('prefix');
        $processingDate = null;
        if ($date) {
            $processingDate = new \DateTime($date);
        } else {
            $processingDate = new \DateTime();
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }
        $output->writeln(sprintf('Using processing date %s', $processingDate->format('d/M/Y')));
        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');

        if ($debug) {
            $output->writeln($bacsService->getHeader());
        }

        $lines = [];
        $creditPayments = [];
        if ($bacsService->hasMandateOrPaymentDebit($prefix, $processingDate)) {
            $lines = $this->runMandatePaymentDebit($input, $output, $processingDate);
        }
        if ($bacsService->hasPaymentCredit()) {
            $creditPayments = $this->runPaymentCredits($input, $output, $processingDate);
        }

        if (count($lines) == 0 && count($creditPayments) == 0) {
            $skipEmail = true;
        }

        if (!$skipEmail) {
            /** @var MailerService $mailer */
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send(
                'Bacs File(s) Ready to Process',
                'bacs@so-sure.com',
                sprintf(
                    'File(s) are ready to process.'
                )
            );
            $output->writeln('Confirmation email sent');
        }

        $output->writeln('Finished');
    }

    private function runMandatePaymentDebit(InputInterface $input, OutputInterface $output, \DateTime $processingDate)
    {
        $skipSftp = true === $input->getOption('skip-sftp');
        $skipS3 = true === $input->getOption('skip-s3');
        $debug = $input->getOption('debug');
        $prefix = $input->getArgument('prefix');

        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');
        /** @var SequenceService $sequenceService */
        $sequenceService = $this->getContainer()->get('app.sequence');
        $serialNumber = $sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER);
        $serialNumber = sprintf("S-%06d", $serialNumber);
        $output->writeln(sprintf('Using serial number %s', $serialNumber));

        $data = [
            'serial-number' => $serialNumber,
        ];

        $output->writeln('Exporting Mandate Cancellations');
        $mandateCancellations = $bacsService->exportMandateCancellations($processingDate);
        $data['ddi-cancellations'] = count($mandateCancellations);
        if ($debug) {
            $output->writeln(json_encode($mandateCancellations, JSON_PRETTY_PRINT));
        }

        $output->writeln('Exporting Mandates');
        $mandates = $bacsService->exportMandates($processingDate, $serialNumber);
        $data['ddi'] = count($mandates);
        if ($debug) {
            $output->writeln(json_encode($mandates, JSON_PRETTY_PRINT));
        }

        $output->writeln('Exporting Debit Payments');
        $debitPayments = $bacsService->exportPaymentsDebits($prefix, $processingDate, $serialNumber, $data);
        $data['debits'] = count($debitPayments);
        if ($debug) {
            $output->writeln(json_encode($debitPayments, JSON_PRETTY_PRINT));
        }

        $lines = array_merge($mandateCancellations, $mandates, $debitPayments);
        if (count($lines) == 0) {
            $output->writeln('No data present. Skipping upload(s)');
            $skipSftp = true;
            $skipS3 = true;
        }

        $now = new \DateTime();
        $filename = sprintf('%s-%s.csv', $processingDate->format('Ymd'), $now->format('U'));
        if (!$skipSftp) {
            $files = $this->uploadSftp(implode(PHP_EOL, $lines), $filename, true);
            if ($debug) {
                $output->writeln(json_encode($files));
            }
            $output->writeln(sprintf('Uploaded sftp file %s', $filename));
        }
        if (!$skipS3) {
            $this->uploadS3(
                implode(PHP_EOL, $lines),
                $filename,
                $serialNumber,
                $processingDate,
                $data
            );
            $output->writeln(sprintf('Uploaded s3 file %s', $filename));
        }

        $this->getManager()->flush();
        $output->writeln('Saved changes to db.');

        return $lines;
    }

    private function runPaymentCredits(InputInterface $input, OutputInterface $output, \DateTime $processingDate)
    {
        $skipSftp = true === $input->getOption('skip-sftp');
        $skipS3 = true === $input->getOption('skip-s3');
        $debug = $input->getOption('debug');
        $prefix = $input->getArgument('prefix');

        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');
        /** @var SequenceService $sequenceService */
        $sequenceService = $this->getContainer()->get('app.sequence');
        $serialNumber = $sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER);
        $serialNumber = sprintf("S-%06d", $serialNumber);
        $output->writeln(sprintf('Using serial number %s', $serialNumber));

        $data = [
            'serial-number' => $serialNumber,
        ];

        $output->writeln('Exporting Credit Payments');
        $creditPayments = $bacsService->exportPaymentsCredits($processingDate, $serialNumber, $data);
        $data['credits'] = count($creditPayments);
        if ($debug) {
            $output->writeln(json_encode($creditPayments, JSON_PRETTY_PRINT));
        }

        if (count($creditPayments) == 0) {
            $output->writeln('No data present. Skipping upload(s)');
            $skipSftp = true;
            $skipS3 = true;
        }

        $now = new \DateTime();
        $creditFilename = sprintf('credits-%s-%s.csv', $processingDate->format('Ymd'), $now->format('U'));
        if (!$skipSftp) {
            $files = $this->uploadSftp(implode(PHP_EOL, $creditPayments), $creditFilename, false);
            if ($debug) {
                $output->writeln(json_encode($files));
            }
            $output->writeln(sprintf('Uploaded sftp file %s', $creditFilename));
        }
        if (!$skipS3) {
            $this->uploadS3(
                implode(PHP_EOL, $creditPayments),
                $creditFilename,
                $serialNumber,
                $processingDate,
                $data
            );
            $output->writeln(sprintf('Uploaded s3 file %s', $creditFilename));
        }

        $this->getManager()->flush();
        $output->writeln('Saved changes to db.');

        return $creditPayments;
    }

    /**
     * @param mixed   $data
     * @param string  $filename
     * @param boolean $debit
     * @return mixed
     * @throws \Exception
     */
    public function uploadSftp($data, $filename, $debit = true)
    {
        $server = $this->getContainer()->getParameter('accesspay_server');
        $username = $this->getContainer()->getParameter('accesspay_username');
        $password = $this->getContainer()->getParameter('accesspay_password');
        $keyfile = $this->getContainer()->getParameter('accesspay_keyfile');

        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);

        $sftp = new SFTP($server);
        $key = new RSA();
        $key->loadKey(file_get_contents($keyfile));
        if (!$sftp->login($username, $key) && !$sftp->login($username, $password)) {
            throw new \Exception('Login Failed');
        }

        if ($debit) {
            $sftp->chdir('Inbound/DD_Collections');
        } else {
            $sftp->chdir('Inbound/DC_Refunds');
        }
        $sftp->put($filename, $tmpFile, SFTP::SOURCE_LOCAL_FILE);
        $files = $sftp->nlist('.', false);

        return $files;
    }

    public function uploadS3($data, $filename, $serialNumber, \DateTime $date, $metadata = null)
    {
        $password = $this->getContainer()->getParameter('accesspay_s3file_password');
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        $encTempFile = sprintf('%s/enc-%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);
        \Defuse\Crypto\File::encryptFileWithPassword($tmpFile, $encTempFile, $password);
        unlink($tmpFile);
        $s3Key = sprintf('%s/bacs/%s', $this->getEnvironment(), $filename);

        $this->getS3()->putObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $s3Key,
            'SourceFile' => $encTempFile,
        ));

        $file = new AccessPayFile();
        $file->setBucket(self::S3_BUCKET);
        $file->setKey($s3Key);
        $file->setDate($date);
        $file->setSerialNumber($serialNumber);

        foreach ($metadata as $key => $value) {
            $file->addMetadata($key, $value);
        }

        $this->getManager()->persist($file);

        unlink($encTempFile);

        return $s3Key;
    }
}
