<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Influencer;
use AppBundle\Document\Reward;

use AppBundle\Repository\ClaimRepository;
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

class PostClaimCommand extends ContainerAwareCommand
{

    const COMMAND_REPORT_NAME = 'Post Claim Report';
    const DEFAULT_EMAIL_ADDRESS = 'kyle@so-sure.com';
    const DEFAULT_REPORT_PERIOD_DAYS = '-8';
    const FILE_NAME = 'postclaims';
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
            ->setName('sosure:postclaims')
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


        /** @var ClaimRepository $claimRepo */
        $claimRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimRepo->findPostClaims($fromDate);

        $formattedData = [];
        try {
            if (count($claims) < 1) {
                $output->writeln('Nothing to process. Ending');
                return;
            }
            $output->writeln('Found total of ' . count($claims) . ' claims since : ' . $fromDate->format('Y-m-d'));

            /** @var Claim $claim */
            foreach ($claims as $claim) {
                $diff = $claim->getReplacementReceivedDate()->diff($claim->getNotificationDate())->format("%a");
                if ($diff < 8) {
                    $formattedData[] = $this->formatPayload($claim);
                }
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


    private function formatPayload(Claim $claim) : array
    {

        /** @var Policy $policy */
        $policy = $claim->getPolicy();

        /** @var Policy $policy */
        return [
            'First name'                      => $policy->getUser()->getFirstName(),
            'Last name'                       => $policy->getUser()->getLastName(),
            'Email'                           => $policy->getUser()->getEmail(),
            'Policy Number'                   => $policy->getId(),
            'Claim Number'                    => $claim->getId(),
            'Claim Type'                      => $claim->getType(),
            'Claim Replacement Date'          => ($claim->getReplacementReceivedDate()) ?
                                                    $claim->getReplacementReceivedDate()->format('Y-m-d H:i:s') : '',
            'FNOL Date'                       => ($claim->getNotificationDate()) ?
                                                    $claim->getNotificationDate()->format('Y-m-d H:i:s') : '',
            'Policy Status'                   => $policy->getStatus(),
            'Link to policy on Admin'         => $this->route->generateUrl('admin_policy', ['id' => $policy->getId()]),
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

        $this->logger->info('Added ' . count($filteredItems) . ' claims to csv');

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
            'SourceFile' => $tmpFile,
        ]);
        return $s3Key;
    }
}
