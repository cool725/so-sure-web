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
            ->addOption(
                'only-credits',
                null,
                InputOption::VALUE_NONE,
                'Only run credits'
            )
            ->addOption(
                'only-debits',
                null,
                InputOption::VALUE_NONE,
                'Only run debits (and mandates)'
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
        $onlyCredits = true === $input->getOption('only-credits');
        $onlyDebits = true === $input->getOption('only-debits');
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

        $debitPayments = [];
        $runDebits = $bacsService->hasMandateOrPaymentDebit($prefix, $processingDate);
        if ($onlyCredits) {
            $runDebits = false;
        }
        if ($runDebits) {
            $debitPayments = $this->runMandatePaymentDebit($input, $output, $processingDate);
        }

        $creditPayments = [];
        $runCredits = $bacsService->hasPaymentCredit();
        if ($onlyDebits) {
            $runCredits = false;
        }
        if ($runCredits) {
            $creditPayments = $this->runPaymentCredits($input, $output, $processingDate);
        }

        if ((count($debitPayments) == 0 && count($creditPayments) == 0) || $debug) {
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

        if ($debug) {
            $skipS3 = true;
            $skipSftp = true;
            $output->writeln('Debug option is set. Skipping sftp & s3 upload');
        }

        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');
        /** @var SequenceService $sequenceService */
        $sequenceService = $this->getContainer()->get('app.sequence');
        $serialNumber = $sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER, !$debug);
        $serialNumber = AccessPayFile::formatSerialNumber($serialNumber);
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
        $mandates = $bacsService->exportMandates($processingDate, $serialNumber, false, !$debug);
        $data['ddi'] = count($mandates);
        if ($debug) {
            $output->writeln(json_encode($mandates, JSON_PRETTY_PRINT));
        }

        $output->writeln('Exporting Debit Payments');
        $debitPayments = $bacsService->exportPaymentsDebits(
            $prefix,
            $processingDate,
            $serialNumber,
            $data,
            false,
            !$debug
        );
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

        if (!$debug) {
            $this->getManager()->flush();
            $output->writeln('Saved changes to db.');
        }

        return $lines;
    }

    private function runPaymentCredits(InputInterface $input, OutputInterface $output, \DateTime $processingDate)
    {
        $skipSftp = true === $input->getOption('skip-sftp');
        $skipS3 = true === $input->getOption('skip-s3');
        $debug = $input->getOption('debug');

        if ($debug) {
            $skipS3 = true;
            $skipSftp = true;
            $output->writeln('Debug option is set. Skipping sftp & s3 upload');
        }
        //$prefix = $input->getArgument('prefix');

        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');
        /** @var SequenceService $sequenceService */
        $sequenceService = $this->getContainer()->get('app.sequence');
        $serialNumber = $sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER, !$debug);
        $serialNumber = AccessPayFile::formatSerialNumber($serialNumber);
        $output->writeln(sprintf('Using serial number %s', $serialNumber));

        $data = [
            'serial-number' => $serialNumber,
        ];

        $output->writeln('Exporting Credit Payments');
        $creditPayments = $bacsService->exportPaymentsCredits($processingDate, $serialNumber, $data, !$debug);
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

        if (!$debug) {
            $this->getManager()->flush();
            $output->writeln('Saved changes to db.');
        }

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
        /** @var BacsService $bacs */
        $bacs =  $this->getContainer()->get('app.bacs');
        return $bacs->uploadSftp($data, $filename, $debit);
    }

    public function uploadS3($data, $filename, $serialNumber, \DateTime $date, $metadata = null)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);

        $uploadFile = new AccessPayFile();
        $uploadFile->setSerialNumber($serialNumber);

        /** @var BacsService $bacs */
        $bacs =  $this->getContainer()->get('app.bacs');
        $s3Key = $bacs->uploadS3($tmpFile, $filename, $uploadFile, $date, $metadata, 'bacs');

        return $s3Key;
    }
}
