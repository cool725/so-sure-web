<?php

namespace AppBundle\Command;

use AppBundle\Classes\PolicyReport;
use AppBundle\Helpers\CsvHelper;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\ReportLine;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\ReportLineRepository;
use Aws\S3\S3Client;
use DateTime;
use AppBundle\Service\PolicyService;
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
    const BATCH_SIZE = 1024;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var DocumentManager  */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var PolicyService */
    protected $policyService;

    /**
     * inserts the required dependencies into the command.
     * @param S3Client        $s3            is the amazon s3 client for uploading generated reports.
     * @param DocumentManager $dm            is the document manager for loading data.
     * @param string          $environment   is the environment name used to upload to the right location in amazon s3.
     * @param LoggerInterface $logger        is used for logging.
     * @param PolicyService   $policyService is used to update report lines on policies.
     */
    public function __construct(
        S3Client $s3,
        DocumentManager $dm,
        $environment,
        LoggerInterface $logger,
        PolicyService $policyService
    ) {
        parent::__construct();
        $this->s3 = $s3;
        $this->dm = $dm;
        $this->environment = $environment;
        $this->logger = $logger;
        $this->policyService = $policyService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this ->setName('sosure:policy:report')
            ->setDescription('Generates reports using common policy data.')
            ->addArgument(
                'reports',
                InputArgument::IS_ARRAY,
                'names of reports to generate'
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'show debug output'
            )
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_REQUIRED,
                'Choose a timezone to use for policies report'
            )
            ->addOption(
                'clear-cache',
                null,
                InputOption::VALUE_NONE,
                'removed all cached report lines and redoes them first'
            )
            ->addOption(
                'only-cache',
                null,
                InputOption::VALUE_NONE,
                'Cache report lines but do not generate reports'
            )
            ->addOption(
                'n',
                0,
                InputOption::VALUE_REQUIRED,
                'Choose a certain maximum number of policies to process. 0 means all of them.'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $clearCache = $input->getOption('clear-cache') == true;
        $onlyCache = $input->getOption('only-cache') == true;
        $reports = $input->getArgument('reports');
        $debug = $input->getOption('debug') == true;
        $n = $input->getOption('n');
        $timezone = new DateTimeZone($input->getOption('timezone') ?: 'UTC');
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        /** @var ReportLineRepository $reportLineRepo */
        $reportLineRepo = $this->dm->getRepository(ReportLine::class);
        // if clear cache then clear cache
        if ($clearCache) {
            $output->writeln('<info>Clearing Cache</info>');
            foreach ($reports as $report) {
                while ($policyRepo->countPoliciesForReportLine($report, true) > 0) {
                    $policies = $policyRepo->findPoliciesForReportLine($report, true);
                    foreach ($policies as $policy) {
                        $this->policyService->deleteReportLines($policy);
                    }
                    $this->dm->flush();
                }
            }
        }
        // First make sure that all reportlines exist.
        $output->writeln('<info>Checking for uncached policies</info>');
        foreach ($reports as $report) {
            $remaining = $policyRepo->countPoliciesForReportLine($report);
            while ($remaining > 0) {
                $output->writeln("<info>{$remaining} uncached {$report} lines");
                $policies = $policyRepo->findPoliciesForReportLine($report);
                foreach ($policies as $policy) {
                    $this->policyService->generateReportLines($policy);
                }
                $this->dm->flush();
                $remaining = $policyRepo->countPoliciesForReportLine($report);
            }
        }
        // If only cache stop now.
        if ($onlyCache) {
            $output->writeln('<info>Caching complete. Terminating.</info>');
            return;
        }
        $start = time();
        // Set up reports to run.
        $executingReports = [];
        foreach ($reports as $report) {
            $createdReport = PolicyReport::createReport($report, $this->dm, $timezone);
            if (!$createdReport) {
                $output->writeln("<error>{$report} is not a valid report type</error>");
                return;
            }
            $executingReports[] = $createdReport;
        }
        foreach ($executingReports as $executingReport) {
            [$min, $max] = $reportLineRepo->getBoundsForType($executingReport->getType());
            $file = [CsvHelper::line(...$executingReport->getHeaders())];
            for ($i = $min; $i <= $max; $i += static::BATCH_SIZE) {
                $reportLines = $reportLineRepo->findInBounds(
                    $executingReport->getType(),
                    $i,
                    min($i + static::BATCH_SIZE, $max)
                );
                /** @var ReportLine $reportLine */
                foreach ($reportLines as $reportLine) {
                    $content = $reportLine->getContent();
                    if ($content) {
                        $file[] = $content;
                    }
                }
            }
            try {
                $this->uploadS3($file, $executingReport->getFile());
                $output->writeln("<info>Uploaded {$executingReport->getFile()}");
            } catch (IOException $e) {
                $output->writeln("<error>{$e->getMessage()}</error>");
            }
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
        if ($result === false) {
            throw new IOException("Could not create tmp file at '{$filename}'");
        }
        $s3Key = sprintf('%s/bi/%s', $this->environment, $filename);
        $this->s3->putObject(['Bucket' => SoSure::S3_BUCKET_ADMIN, 'Key' => $s3Key, 'SourceFile' => $filename]);
        unlink($filename);
        return $s3Key;
    }
}
