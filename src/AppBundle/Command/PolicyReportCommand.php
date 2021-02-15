<?php

namespace AppBundle\Command;

use AppBundle\Classes\PolicyReport;
use AppBundle\Document\PhonePolicy;
use AppBundle\Repository\PhonePolicyRepository;
use Aws\S3\S3Client;
use DateTime;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use IOException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Classes\SoSure;

/**
 * Command for exporting CSV reports on various collections of company data.
 */
class PolicyReportCommand extends ContainerAwareCommand
{
    const INTERVAL = 'P13D';

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var DocumentManager  */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * inserts the required dependencies into the command.
     * @param S3Client        $s3          is the amazon s3 client for uploading generated reports.
     * @param DocumentManager $dm          is the document manager for loading data.
     * @param string          $environment is the environment name used to upload to the right location in amazon s3.
     * @param LoggerInterface $logger      is used for logging.
     */
    public function __construct(
        S3Client $s3,
        DocumentManager $dm,
        $environment,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->s3 = $s3;
        $this->dm = $dm;
        $this->environment = $environment;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this ->setName('sosure:policy:report')
            ->setDescription('Generates reports using common policy data.')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'show debug output')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Choose a timezone to use for policies report')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Choose a report to generate', 'policy');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start = time();
        // Set up reports to run.
        $reportName = $input->getOption('report');
        $debug = $input->getOption('debug') == true;
        $timezone = new DateTimeZone($input->getOption('timezone') ?: 'UTC');
        $report = PolicyReport::createReport($reportName, $this->dm, $timezone);
        if (!$report) {
            $output->writeln("<error>{$reportName} is not a valid report type</error>");
            return;
        }
        // Iterating over the policies.
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        $now = new \DateTime();
        $begin = (clone $now)->sub(new \DateInterval('P15M'));
        while ($begin < $now) {
            $batchStart = time();
            $top = ($begin < $now) ? (clone $begin)->add(new \DateInterval(self::INTERVAL)) : null;
            $policies = $phonePolicyRepo->findAllStartedPolicies($begin, $top);
            $output->writeln(sprintf(
                '%s - %s: %d',
                $begin->format('Y-m-d'),
                $top ? $top->format('Y-m-d') : '',
                count($policies)
            ));
            foreach ($policies as $policy) {
                try {
                    $report->process($policy);
                } catch (RuntimeException $e) {
                    $output->writeln("<error>{$e->getMessage()}</error>");
                }
            }
            $begin->add(new \DateInterval(self::INTERVAL));
            if (count($policies) > 0) {
                $output->writeln(sprintf(
                    '%f -- %f / row',
                    time() - $batchStart,
                    (time() - $batchStart) / count($policies)
                ));
            }
        }
        // Now run the reports.
        // Output and upload.
        if ($debug) {
            $output->writeln("<info>{$report->getFile()}</info>");
            $output->writeln($report->getLines());
        }
        try {
            $this->uploadS3($report->getLines(), $report->getFile());
            $output->writeln("<info>Uploaded {$report->getFile()}");
        } catch (IOException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        $time = time() - $start;
        $output->writeln("Execution completed in {$time} seconds.");
    }

    /**
     * Uploads an array of data to s3 as lines in a file.
     * @param array  $data     is the data to write to the file.
     * @param string $filename is the name of the file to write it to.
     * @return string the key to the file on s3.
     * @throws IOException when failure occurs.
     */
    private function uploadS3($data, $filename)
    {
        $result = file_put_contents($filename, implode(PHP_EOL, $data));
        if (!$result) {
            throw new IOException("Could not create tmp file at '{$filename}'");
        }
        $s3Key = sprintf('%s/bi/%s', $this->environment, $filename);
        $this->s3->putObject(['Bucket' => SoSure::S3_BUCKET_ADMIN, 'Key' => $s3Key, 'SourceFile' => $filename]);
        unlink($filename);
        return $s3Key;
    }
}
