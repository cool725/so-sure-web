<?php

namespace AppBundle\Command;

use AppBundle\Classes\PolicyReport;
use AppBundle\Document\PhonePolicy;
use AppBundle\Repository\PhonePolicyRepository;
use Aws\S3\S3Client;
use DateTime;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\MongoDBException;
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
    const START_INTERVAL = 'P15M';

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
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_OPTIONAL,
                'Choose a timezone to use for policies report',
                'Europe/London'
            )
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'How many policies to process in batches', 1000)
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Choose a report to generate', 'policy');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $batchStart = time();
        $now = new \DateTime();
        $policyCounter = 0;
        $begin = (clone $now)->sub(new \DateInterval(self::START_INTERVAL));

        // Set up reports to run.
        $reportName = $input->getOption('report');
        $limit = $input->getOption('limit');
        $debug = $input->getOption('debug') == true;
        $timezone = new DateTimeZone($input->getOption('timezone'));

        $report = PolicyReport::createReport($reportName, $this->dm, $timezone, $this->logger);
        if (!$report) {
            $output->writeln("<error>{$reportName} is not a valid report type</error>");
            return;
        }
        // Iterating over the policies.
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);

        // Grab total count of all policies to see if we match up
        $policyTotalCount = $phonePolicyRepo->findPoliciesQueryByDate($begin, $now, 0, 0, true);
        $output->writeln(sprintf(
            'Starting at: %s...Time Range: %s - %s. Total policy count %d.',
            $now ? $now->format('Y-m-d H:i:s') : '',
            $begin->format('Y-m-d'),
            $now ? $now->format('Y-m-d') : '',
            $policyTotalCount
        ));

        while ($begin < $now) {
            $top = ($begin < $now) ? (clone $begin)->add(new \DateInterval(self::INTERVAL)) : null;
            $policyCount = $phonePolicyRepo->findPoliciesQueryByDate($begin, $top, 0, 0, true);
            $policyCounter += $policyCount;
            $offsetCounter = 0;
            // if we need to handle many policies, lets handle in batches
            if ($policyCount > 2000) {
                $policies = $phonePolicyRepo->findPoliciesQueryByDate($begin, $top);
                $policyArr = iterator_to_array($policies->getIterator(), true);
                for ($v = 0; $v <= $policyCount; $v += $limit) {
                    $starttime = time();

                    if (!$policies instanceof \Doctrine\MongoDB\Query\Query) {
                        throw new MongoDBException('Invalid return type for query');
                    }

                    $policyBatch = array_slice($policyArr, $offsetCounter, $limit, true);

                    $output->writeln(sprintf(
                        'Time Range: %s - %s. Offset count %d. Policies processing: %d',
                        $begin->format('Y-m-d'),
                        $top ? $top->format('Y-m-d') : '',
                        $offsetCounter,
                        count($policyBatch)
                    ));

                    try {
                        if ($policyBatch) {
                            foreach ($policyBatch as $policy) {
                                $report->process($policy);
                            }
                            sleep(1);
                            // Clear for each batch
                            $this->dm->clear();
                        }
                    } catch (\Exception $e) {
                        $output->writeln(sprintf(
                            'Error found: %s',
                            $e->getMessage()
                        ));
                    }

                    $endtime = time();
                    $duration = ($endtime - $starttime) - 1;
                    $output->writeln(sprintf(
                        'Time Range: %s - %s. Time taken: %s: Offset count %d.',
                        $begin->format('Y-m-d'),
                        $top ? $top->format('Y-m-d') : '',
                        $duration,
                        $offsetCounter
                    ));
                    $offsetCounter += $limit;
                }
                $policyTotalCount -= $policyCount;
                $output->writeln(sprintf(
                    'Time Range: %s - %s. Complete. Policies remaining: %d',
                    $begin->format('Y-m-d'),
                    $top ? $top->format('Y-m-d') : '',
                    $policyTotalCount
                ));

                $begin->add(new \DateInterval(self::INTERVAL));
            } else {
                $starttime = time();
                $output->writeln(sprintf(
                    'SB: Policy count %d.',
                    $policyCount
                ));
                if ($policyCount == 0) {
                    $begin->add(new \DateInterval(self::INTERVAL));
                    continue;
                }
                $policies = $phonePolicyRepo->findPoliciesQueryByDate($begin, $top);
                if (!$policies instanceof \Doctrine\MongoDB\Query\Query) {
                    throw new MongoDBException('Invalid return type for query');
                }
                // process entire batch as its small enough
                $policyBatch = iterator_to_array($policies->getIterator(), true);
                try {
                    if ($policyBatch) {
                        foreach ($policyBatch as $policy) {
                            $report->process($policy);
                        }
                        sleep(1);
                        // Clear for each batch
                        $this->dm->clear();
                    }
                } catch (\Exception $e) {
                    $output->writeln(sprintf(
                        'Error found: %s',
                        $e->getMessage()
                    ));
                    $begin->add(new \DateInterval(self::INTERVAL));
                    continue;
                }

                $endtime = time();
                $duration = ($endtime - $starttime) - 1;
                $policyTotalCount -= $policyCount;
                $output->writeln(sprintf(
                    'SB: Time Range: %s - %s. Time taken: %s. Policies remaining: %d',
                    $begin->format('Y-m-d'),
                    $top ? $top->format('Y-m-d') : '',
                    $duration,
                    $policyTotalCount
                ));
                $begin->add(new \DateInterval(self::INTERVAL));
            }
        }
        if ($policyCounter > 0) {
            $output->writeln(sprintf(
                '%f -- %f / row',
                time() - $batchStart,
                (time() - $batchStart) / $policyCounter
            ));
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
        $time = time() - $batchStart;
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
