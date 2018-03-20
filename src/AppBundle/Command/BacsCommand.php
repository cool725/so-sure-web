<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Repository\UserRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

class BacsCommand extends BaseCommand
{
    use DateTrait;

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
        ;
    }

    /**
     * @param InputInterface $input
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
        $processingDate = null;
        if ($date) {
            $processingDate = new \DateTime($date);
        } else {
            $processingDate = new \DateTime();
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }
        $output->writeln(sprintf('Using processing date %s', $processingDate->format('d-M-Y')));

        if ($debug) {
            $output->writeln($this->getHeader());
        }

        $output->writeln('Exporting Mandates');
        $mandates = $this->exportMandates($processingDate);
        if ($debug) {
            $output->writeln(json_encode($mandates, JSON_PRETTY_PRINT));
        }

        $output->writeln('Exporting Payments');
        $payments = $this->exportPayments($processingDate);
        if ($debug) {
            $output->writeln(json_encode($payments, JSON_PRETTY_PRINT));
        }

        $lines = array_merge($mandates, $payments);
        if (($debug && count($lines) == 1) || count($lines) == 0) {
            $output->writeln('No data present. Skipping upload(s)');
            $skipSftp = true;
            $skipS3 = true;
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
            $this->uploadS3(implode(PHP_EOL, $lines), $filename);
            $output->writeln(sprintf('Uploaded s3 file %s', $filename));
        }

        $this->getManager()->flush();
        $output->writeln('Saved changes to db. Finished');
        
        // TODO: EMail bacs@so-sure.com
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

    private function exportMandates(\DateTime $date, $includeHeader = false)
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
                sprintf('"%s"', $date->format('d-m-y')),
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
        }

        return $lines;
    }

    private function exportPayments(\DateTime $date, $includeHeader = false)
    {
        $lines = [];
        if ($includeHeader) {
            $lines[] = $this->getHeader();
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

    public function uploadS3($data, $filename)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);
        $s3Key = sprintf('%s/bi/%s', $this->getEnvironment(), $filename);

        $this->getS3()->putObject(array(
            'Bucket' => 'admin.so-sure.com',
            'Key'    => $s3Key,
            'SourceFile' => $tmpFile,
        ));

        unlink($tmpFile);

        return $s3Key;
    }
}
