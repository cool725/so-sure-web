<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
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
        $debug = $input->getOption('debug');
        $date = $input->getOption('date');
        $skipSftp = true === $input->getOption('skip-sftp');
        $skipS3 = true === $input->getOption('skip-s3');
        $skipEmail = true === $input->getOption('skip-email');
        $prefix = $input->getArgument('prefix');
        $processingDate = null;
        if ($date) {
            $processingDate = new \DateTime($date);
        } else {
            $processingDate = new \DateTime();
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }
        $output->writeln(sprintf('Using processing date %s', $processingDate->format('d/M/Y')));

        $sequenceService = $this->getContainer()->get('app.sequence');
        $serialNumber = $sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER);
        $serialNumber = sprintf("S-%06d", $serialNumber);
        $output->writeln(sprintf('Using serial number %s', $serialNumber));

        if ($debug) {
            $output->writeln($this->getHeader());
        }
        $data = [];

        $output->writeln('Exporting Mandate Cancellations');
        $mandateCancellations = $this->exportMandateCancellations($processingDate, $serialNumber);
        $data['ddi-cancellations'] = count($mandateCancellations);
        if ($debug) {
            $output->writeln(json_encode($mandateCancellations, JSON_PRETTY_PRINT));
        }

        $output->writeln('Exporting Mandates');
        $mandates = $this->exportMandates($processingDate, $serialNumber);
        $data['ddi'] = count($mandates);
        if ($debug) {
            $output->writeln(json_encode($mandates, JSON_PRETTY_PRINT));
        }

        $output->writeln('Exporting Payments');
        $payments = $this->exportPayments($prefix, $processingDate);
        if ($debug) {
            $output->writeln(json_encode($payments, JSON_PRETTY_PRINT));
        }

        $lines = array_merge($mandateCancellations, $mandates, $payments);
        if (count($lines) == 0) {
            $output->writeln('No data present. Skipping upload(s)');
            $skipSftp = true;
            $skipS3 = true;
            $skipEmail = true;
        }

        $now = new \DateTime();
        $filename = sprintf('%s-%s.csv', $processingDate->format('Ymd'), $now->format('U'));
        if (!$skipSftp) {
            $files = $this->uploadSftp(implode(PHP_EOL, $lines), $filename);
            if ($debug) {
                $output->writeln(json_encode($files));
            }
            $output->writeln(sprintf('Uploaded sftp file %s', $filename));
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), $filename, $processingDate, $data);
            $output->writeln(sprintf('Uploaded s3 file %s', $filename));
        }

        $this->getManager()->flush();
        $output->writeln('Saved changes to db.');

        if (!$skipEmail) {
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send(
                'Bacs File Ready to Process',
                'bacs@so-sure.com',
                sprintf('File %s is ready to process. Data: %s', $filename, json_encode($data))
            );
            $output->writeln('Confirmation email sent');
        }

        $output->writeln('Finished');
    }

    private function getHeader()
    {
        return implode(',', [
            '"Processing Date"',
            '"Action"',
            '"BACS Transaction Code"',
            '"Name"',
            '"Sort Code"',
            '"Account"',
            '"Amount"',
            '"DDI Reference"',
            '"UserId"',
            '"PolicyId"',
            '"PaymentId"',
        ]);
    }

    private function exportMandates(\DateTime $date, $serialNumber, $includeHeader = false)
    {
        /** @var UserRepository $repo */
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findBy(['paymentMethod.bankAccount.mandateStatus' => BankAccount::MANDATE_PENDING_INIT]);
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        foreach ($users as $user) {
            /** @var User $user */
            /** @var BacsPaymentMethod $paymentMethod */
            $paymentMethod = $user->getPaymentMethod();
            $lines[] = implode(',', [
                sprintf('"%s"', $date->format('d/m/y')),
                '"Initial Mandate"',
                '"0N"', // new Auddis
                sprintf('"%s"', $paymentMethod->getBankAccount()->getAccountName()),
                sprintf('"%s"', $paymentMethod->getBankAccount()->getSortCode()),
                sprintf('"%s"', $paymentMethod->getBankAccount()->getAccountNumber()),
                '"0"', // £0 for Addis setup
                sprintf('"%s"', $paymentMethod->getBankAccount()->getReference()),
                sprintf('"%s"', $user->getId()),
                '""',
                '""',
            ]);
            $paymentMethod->getBankAccount()->setMandateStatus(BankAccount::MANDATE_PENDING_APPROVAL);
            $paymentMethod->getBankAccount()->setMandateSerialNumber($serialNumber);

            // do not attempt to take payment until 2 business days after to allow for mandate
            $initialPaymentSubmissionDate = new \DateTime();
            $initialPaymentSubmissionDate = $this->addBusinessDays($initialPaymentSubmissionDate, 2);
            $paymentMethod->getBankAccount()->setInitialPaymentSubmissionDate($initialPaymentSubmissionDate);
        }

        return $lines;
    }

    private function exportMandateCancellations(\DateTime $date, $serialNumber, $includeHeader = false)
    {
        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');
        $cancellations = $bacsService->getBacsCancellations();
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        foreach ($cancellations as $cancellation) {
            $lines[] = implode(',', [
                sprintf('"%s"', $date->format('d/m/y')),
                '"Cancel Mandate"',
                '"0C"', // new Auddis
                sprintf('"%s"', $cancellation['accountName']),
                sprintf('"%s"', $cancellation['sortCode']),
                sprintf('"%s"', $cancellation['accountNumber']),
                '"0"', // £0 for Addis setup
                sprintf('"%s"', $cancellation['reference']),
                sprintf('"%s"', $cancellation['id']),
                '""',
                '""',
            ]);
        }

        return $lines;
    }

    private function exportPayments($prefix, \DateTime $date, $includeHeader = false)
    {
        $now = new \DateTime();
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
        }
        /** @var PaymentService $paymentService */
        $paymentService = $this->getContainer()->get('app.payment');

        /** @var BacsService $bacsService */
        $bacsService = $this->getContainer()->get('app.bacs');

        // get all scheduled payments for bacs that should occur within the next 3 business days in order to allow
        // time for the bacs cycle
        $advanceDate = clone $date;
        $advanceDate = $this->addBusinessDays($advanceDate, 3);

        $scheduledPayments = $paymentService->getAllValidScheduledPaymentsForType(
            $prefix,
            BacsPaymentMethod::class,
            $advanceDate
        );
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            /** @var BacsPaymentMethod $bacs */
            $bacs = $scheduledPayment->getPolicy()->getUser()->getPaymentMethod();
            if (!$bacs || !$bacs->getBankAccount()) {
                $msg = sprintf(
                    'Skipping scheduled payment %s as unable to determine payment method or missing bank account',
                    $scheduledPayment->getId()
                );
                $this->getContainer()->get('logger')->warning($msg);
                continue;
            }

            $bankAccount = $bacs->getBankAccount();
            if ($bankAccount->getMandateStatus() != BankAccount::MANDATE_SUCCESS) {
                $msg = sprintf(
                    'Skipping scheduled payment %s as mandate is not enabled (%s)',
                    $scheduledPayment->getId(),
                    $bankAccount->getMandateStatus()
                );
                // for first payment, would expected that mandate may not yet be setup
                if ($bankAccount->isFirstPayment()) {
                    $this->getContainer()->get('logger')->info($msg);
                } else {
                    $this->getContainer()->get('logger')->warning($msg);
                }
                continue;
            }
            if (!$bankAccount->allowedSubmission()) {
                $msg = sprintf(
                    'Skipping payment %s as submission is not yet allowed (must be at least %s)',
                    $scheduledPayment->getId(),
                    $bankAccount->getInitialPaymentSubmissionDate()->format('d/m/y')
                );
                $this->getContainer()->get('logger')->error($msg);
                continue;
            }
            if (!$bankAccount->allowedProcessing($scheduledPayment->getScheduled())) {
                $msg = sprintf(
                    'Skipping scheduled payment %s as processing date is not allowed (%s / initial: %s)',
                    $scheduledPayment->getId(),
                    $scheduledPayment->getScheduled()->format('d/m/y'),
                    $bankAccount->isFirstPayment() ? 'yes' : 'no'
                );
                $this->getContainer()->get('logger')->error($msg);
                continue;
            }

            $payment = $bacsService->bacsPayment(
                $scheduledPayment->getPolicy(),
                'Scheduled Payment',
                $scheduledPayment->getAmount()
            );
            $scheduledPayment->setPayment($payment);

            $lines[] = implode(',', [
                sprintf('"%s"', $scheduledPayment->getScheduled()->format('d/m/y')),
                '"Scheduled Payment"',
                $bankAccount->isFirstPayment() ? '"01"' : '"17"',
                sprintf('"%s"', $bankAccount->getAccountName()),
                sprintf('"%s"', $bankAccount->getSortCode()),
                sprintf('"%s"', $bankAccount->getAccountNumber()),
                sprintf('"%0.2f"', $scheduledPayment->getAmount()),
                sprintf('"%s"', $bankAccount->getReference()),
                sprintf('"%s"', $scheduledPayment->getPolicy()->getUser()->getId()),
                sprintf('"%s"', $scheduledPayment->getPolicy()->getId()),
                sprintf('"SP-%s"', $scheduledPayment->getId()),
            ]);
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);
            if ($bankAccount->isFirstPayment()) {
                $bankAccount->setFirstPayment(false);
            }
        }

        return $lines;
    }

    /**
     * @param $data
     * @param $filename
     * @return mixed
     * @throws \Exception
     */
    public function uploadSftp($data, $filename)
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

        $sftp->chdir('Inbound/DD_Collections');
        $sftp->put($filename, $tmpFile, SFTP::SOURCE_LOCAL_FILE);
        $files = $sftp->nlist('.', false);

        return $files;
    }

    public function uploadS3($data, $filename, \DateTime $date, $metadata = null)
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

        foreach ($metadata as $key => $value) {
            $file->addMetadata($key, $value);
        }

        $this->getManager()->persist($file);

        unlink($encTempFile);

        return $s3Key;
    }
}
