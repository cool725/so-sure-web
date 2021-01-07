<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Influencer;
use AppBundle\Document\Reward;

use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\RewardRepository;
use AppBundle\Service\MailerService;
use AppBundle\Service\RouterService;
use Aws\S3\S3Client;
use CensusBundle\Service\SearchService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\DateTrait;

class CoolOffCommand extends ContainerAwareCommand
{

    const COMMAND_REPORT_NAME = 'Cool-Off Report';
    const DEFAULT_EMAIL_ADDRESS = 'tech+ops@so-sure.com';
    const DEFAULT_REPORT_PERIOD_DAYS = '-14';
    const FILE_NAME = 'cooloff';
    const BUCKET_FOLDER = 'reports';

    use DateTrait;

    /**
     * inserts the required dependencies into the command.
     * @param S3Client        $s3            is the amazon s3 client for uploading generated reports.
     * @param DocumentManager $dm            is the document manager for loading data.
     * @param string          $environment   is the environment name used to upload to the right location in amazon s3.
     * @param LoggerInterface $logger        is used for logging.
     */

    /** @var MailerService */
    protected $mailerService;

    /** @var S3Client */
    protected $s3;

    /** @var DocumentManager  */
    protected $dm;

    /** @var Client  */
    protected $redis;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $environment;

    /** @var RouterService */
    protected $route;

    protected $emailAccounts;

    /** @var bool */
    protected $skipS3;

    public function __construct(
        DocumentManager $dm,
        S3Client $s3,
        MailerService $mailerService,
        Client $redis,
        $environment,
        LoggerInterface $logger,
        RouterService $route
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->s3 = $s3;
        $this->redis = $redis;
        $this->mailerService = $mailerService;
        $this->environment = $environment;
        $this->logger = $logger;
        $this->route = $route;
    }


    protected function configure()
    {
        $date = new \DateTime(date("Y-m-d"));
        $defaultfromDate = $date->modify(self::DEFAULT_REPORT_PERIOD_DAYS . ' day');

        $this
            ->setName('sosure:cooloff')
            ->setDescription('Show post claims from X days back')
            ->addOption(
                'from-date',
                null,
                InputOption::VALUE_OPTIONAL,
                'Searching claims from amount of days specified to current date',
                $defaultfromDate
            )
            ->addOption(
                'email-accounts',
                null,
                InputOption::VALUE_OPTIONAL,
                'What email address(s) to send to',
                self::DEFAULT_EMAIL_ADDRESS
            )
            ->addOption(
                'skip-s3',
                null,
                InputOption::VALUE_OPTIONAL,
                'Skip s3 upload',
                false
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         * Check if date has been passed, else use default 7days back
         */
        $fromDate = $input->getOption('from-date');
        $this->skipS3 = $input->getOption('skip-s3');

        if (!$fromDate instanceof \DateTime) {
            $date = new \DateTime(date("Y-m-d"));
            $fromDate = $date->modify($fromDate . ' day');
        }

        /**
         * Set variable to be used by sendCSV function later
         */
        $this->emailAccounts = $input->getOption('email-accounts');


        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesFromStartDate($fromDate);

        $formattedData = [];
        try {
            if (count($policies) < 1) {
                $output->writeln('Nothing to process. Ending');
                return;
            }
            $output->writeln('Found total of ' . count($policies) . ' policies since : ' . $fromDate->format('Y-m-d'));

            foreach ($policies as $policy) {
                $formattedData[] = $this->formatPayload($policy);
            }

            if (!empty($formattedData)) {
                $output->writeln('Formatted payload...creating CSV');
                $this->sendCsv($formattedData, $output);
                $output->writeln('Inserted ' . count($formattedData) . ' records');
            }
        } catch (\Exception $exc) {
            $output->writeln($exc->getMessage());
        }
        $output->writeln('All done!');
    }


    private function formatPayload(Policy $policy) : array
    {
        /** @var Policy $policy */

        $startDate = '';
        if ($policy->getStart() instanceof \DateTime) {
            $startDate = $policy->getStart()->format('Y-m-d H:i:s');
        }
        $endDate = '';
        if ($policy->getEnd() instanceof \DateTime) {
            $endDate = $policy->getEnd()->format('Y-m-d H:i:s');
        }
        $downloadDate = '';
        if ($policy->getUser()->getFirstLoginInApp() instanceof \DateTime) {
            $downloadDate = $policy->getUser()->getFirstLoginInApp()->format('Y-m-d H:i:s');
        }
        return [
            'Policy Number'                   => $policy->getId(),
            'App download date'               => $downloadDate,
            'Policy Start date'               => $startDate,
            'Policy cancellation date'        => $endDate,
        ];
    }

    private function sendCsv(array $filteredItems, OutputInterface $output)
    {
        /**
         * This part assuming you have set the const variable of default address
         * Sends to single or multiple email accounts
         * To send to multiple accounts, use comma seperation
         */
        if ($this->emailAccounts !== self::DEFAULT_EMAIL_ADDRESS) {
            $emailArr = explode(",", $this->emailAccounts);
            $emailF = [];
            foreach ($emailArr as $emailAccount) {
                if (filter_var($emailAccount, FILTER_VALIDATE_EMAIL)) {
                    $emailF[] = $emailAccount;
                }
            }
            if (!empty($emailF)) {
                $this->emailAccounts = $emailF;
            } else {
                $this->emailAccounts = self::DEFAULT_EMAIL_ADDRESS;
            }
        }

        $this->logger->info('Added ' . count($filteredItems) . ' records to csv');

        /** create the csv tmp file */
        $fileName = self::FILE_NAME.'-'.time().".csv";
        $file = "/tmp/" . $fileName;
        $cspReport = fopen($file, "w");
        if (isset($filteredItems['0'])) {
            fputcsv($cspReport, array_keys($filteredItems['0']));
            foreach ($filteredItems as $values) {
                fputcsv($cspReport, $values);
            }
        }
        fclose($cspReport);
        $output->writeln('Completed CSV..sending mail.');

        $csvS3Text = "<br />";
        if (!$this->skipS3) {
            $s3Key = sprintf('%s/'. self::BUCKET_FOLDER.'/%s', $this->environment, $fileName);
            $this->uploadS3($file, $s3Key);
            $csvS3Text = "File S3 Location: " . $s3Key . "<br />";
        }

        $this->mailerService->send(
            self::COMMAND_REPORT_NAME,
            $this->emailAccounts,
            "<h4>".self::COMMAND_REPORT_NAME . ": </h4><br /><br />"
            . "Number of Claims found: " . count($filteredItems) . "<br />"
            . "File: " . $fileName . "<br /><br />"
            . $csvS3Text,
            null,
            [$file]
        );

        unset($file);
        $output->writeln('Mail sent!');
    }

    private function uploadS3($tmpFile, $s3Key)
    {
        $this->s3->putObject([
            'Bucket' => SoSure::S3_BUCKET_ADMIN,
            'Key' => $s3Key,
            'SourceFile' => $tmpFile
        ]);
        return $s3Key;
    }
}
