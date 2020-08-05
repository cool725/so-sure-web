<?php

namespace AppBundle\Command;

use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Note\Note;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\Reward;
use AppBundle\Document\SCode;
use AppBundle\Document\DateTrait;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\ReferralBonus;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\RewardRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use Aws\S3\S3Client;
use CensusBundle\Service\SearchService;
use DateTime;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Helpers\CsvHelper;

/**
 * Command for exporting CSV reports on various collections of company data.
 */
class BICommand extends ContainerAwareCommand
{
    use DateTrait;

    const EXPORT_OPTIONS = [
        'policies',
        'claims',
        'users',
        'invitations',
        'connections',
        'phones',
        'unpaidCalls',
        'rewards',
        'rewardsBudget',
        'bacsPayments',
        'referrals'
    ];

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var DocumentManager  */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var SearchService */
    protected $searchService;

    /**
     * inserts the required dependencies into the command.
     * @param S3Client        $s3            is the amazon s3 client for uploading generated reports.
     * @param DocumentManager $dm            is the document manager for loading data.
     * @param string          $environment   is the environment name used to upload to the right location in amazon s3.
     * @param LoggerInterface $logger        is used for logging.
     * @param SearchService   $searchService provides geographical information about users.
     */
    public function __construct(
        S3Client $s3,
        DocumentManager $dm,
        $environment,
        LoggerInterface $logger,
        SearchService $searchService
    ) {
        parent::__construct();
        $this->s3 = $s3;
        $this->dm = $dm;
        $this->environment = $environment;
        $this->logger = $logger;
        $this->searchService = $searchService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $onlyMessage = 'Only run one export [' . implode(', ', self::EXPORT_OPTIONS) . ']';
        $this
            ->setName('sosure:bi')
            ->setDescription('Run a bi export')
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'show debug output'
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                $onlyMessage
            )
            ->addOption(
                'exclude',
                'E',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Datasets to exclude'
            )
            ->addOption(
                'skip-s3',
                null,
                InputOption::VALUE_NONE,
                'Skip s3 upload'
            )
            ->addOption(
                'timezone',
                null,
                InputOption::VALUE_REQUIRED,
                'Choose a timezone to use for policies report'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the date you want to query transactions for - MM/YY.'
            )
        ;
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get arguments.
        $only = $input->getOption('only');
        $exclude = $input->getOption('exclude');
        $skipS3 = true === $input->getOption('skip-s3');
        $debug = $input->getOption('debug');
        $timezone = new DateTimeZone($input->getOption('timezone') ?: 'UTC');
        // Figure out exports to run.
        $exports = [];
        foreach (self::EXPORT_OPTIONS as $export) {
            if ((!$only || $only == $export) && !in_array($export, $exclude)) {
                $exports[] = $export;
            }
        }
        // Run exports.
        foreach ($exports as $export) {
            $output->writeln($export);
            $lines = [];
            switch ($export) {
                case 'claims':
                    $lines = $this->exportClaims($skipS3, $timezone);
                    break;
                case 'invitations':
                    $lines = $this->exportInvitations($skipS3, $timezone);
                    break;
                case 'connections':
                    $lines = $this->exportConnections($skipS3, $timezone);
                    break;
                case 'phones':
                    $lines = $this->exportPhones($skipS3, $timezone);
                    break;
                case 'rewards':
                    $lines = $this->exportRewards($skipS3);
                    break;
                case 'rewardsBudget':
                    $lines = $this->exportRewardsBudget($skipS3);
                    break;
                case 'bacsPayments':
                    $lines = $this->exportBacsPayments($skipS3);
                    break;
                case 'referrals':
                    $lines = $this->exportReferrals($skipS3);
                    break;
                case 'users':
                    $lines = $this->exportUsers($skipS3, $timezone);
                    break;
                case 'unpaidCalls':
                    $lines = $this->exportUnpaidCalls($skipS3, $timezone);
                    break;
            }
            if ($debug) {
                foreach ($lines as $line) {
                    $output->writeln($line);
                }
            }
        }
    }

    /**
     * Creates an array in the style of a csv file containing the current data on phones.
     * @param boolean      $skipS3   says whether we should upload the created array to S3 storage.
     * @param DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of phone data.
     */
    private function exportPhones($skipS3, DateTimeZone $timezone)
    {
        /** @var PhoneRepository $repo */
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();
        $lines = [];
        $lines[] = implode(',', [
            '"Make"',
            '"Model"',
            '"Memory"',
            '"Current Monthly Cost"',
            '"Current Yearly Cost"',
            '"Original Retail Price"',
            '"Current Retail Price"'
        ]);
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $monthlyPrice = $phone->getCurrentMonthlyPhonePrice();
            $yearlyPrice = $phone->getCurrentYearlyPhonePrice();
            $lines[] = implode(',', [
                sprintf('"%s"', $phone->getMake()),
                sprintf('"%s"', $phone->getModel()),
                sprintf('"%s"', $phone->getMemory()),
                sprintf('"%0.2f"', $monthlyPrice ? $monthlyPrice->getMonthlyPremiumPrice() : ''),
                sprintf('"%0.2f"', $yearlyPrice ? $yearlyPrice->getYearlyPremiumPrice() : ''),
                sprintf('"%0.2f"', $phone->getInitialPrice()),
                sprintf('"%0.2f"', $phone->getCurrentRetailPrice())
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'phones.csv');
        }
        return $lines;
    }

    /**
     * Creates an array in the style of a csv file containing the current data on claims.
     * @param boolean      $skipS3   says whether we should upload the created array to S3 storage.
     * @param DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of claim data.
     */
    private function exportClaims($skipS3, DateTimeZone $timezone)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Policy Number"',
            '"Policy Start Date"',
            '"FNOL"',
            '"Postcode"',
            '"Claim Number"',
            '"Claim Type"',
            '"Claim Status"',
            '"Claim Location"',
            '"Policy Cancellation Date"',
            '"Policy Cancellation Type"',
            '"Policy Connections"',
            '"Claim Suspected Fraud"',
            '"Policy upgraded"',
            '"Age of Policy Holder"',
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
            '"Latest Referer"',
            '"First Campaign Name"',
            '"First Campaign Source"',
            '"First Referer"',
            '"Claim Initial Suspicion"',
            '"Claim Final Suspicion"',
            '"Claim Replacement Received Date"',
            '"Claim handling team"',
            '"Total cost of claim"',
            '"Claim Closed Date"',
            "'Risk Rating'",
            "'Network'",
            "'Phone Make/Model/Memory'"
        ]);
        foreach ($claims as $claim) {
            /** @var Claim $claim */
            $policy = $claim->getPolicy();
            $phonePolicy = null;
            if ($policy instanceof PhonePolicy) {
                $phonePolicy = $policy;
            }
            // mainly for dev use
            if (!$policy) {
                $this->logger->error(sprintf('Missing policy for claim %s', $claim->getId()));
                continue;
            }
            $user = $policy->getUser();
            $billing = $user->getBillingAddress();
            $census = $billing ? $this->searchService->findNearest($billing->getPostcode()) : null;
            $income = $billing ? $this->searchService->findIncome($billing->getPostcode()) : null;
            $lines[] = implode(',', [
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%s"', $this->timezoneFormat($policy->getStart(), $timezone, 'Y-m-d H:i:s')),
                sprintf('"%s"', $this->timezoneFormat($claim->getNotificationDate(), $timezone, 'Y-m-d H:i:s')),
                sprintf('"%s"', $billing ? $billing->getPostcode() : ''),
                sprintf('"%s"', $claim->getNumber()),
                sprintf('"%s"', $claim->getType()),
                sprintf('"%s"', $claim->getStatus()),
                sprintf('"%s"', $claim->getLocation()),
                sprintf(
                    '"%s"',
                    $policy->getCancelledReason() ?
                        $this->timezoneFormat($policy->getEnd(), $timezone, 'Y-m-d H:i:s') : ''
                ),
                sprintf('"%s"', $policy->getCancelledReason() ? $policy->getCancelledReason() : ''),
                sprintf('"%s"', count($policy->getStandardConnections())),
                'N/A',
                sprintf(
                    '"%s"',
                    $policy->getCancelledReason() && $policy->getCancelledReason() == Policy::CANCELLED_UPGRADE ?
                        'yes' : 'no'
                ),
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $census ? $census->getSubgrp() : ''),
                sprintf('"%s"', $user->getGender() ? $user->getGender() : ''),
                $income ? sprintf('"%0.0f"', $income->getTotal()->getIncome()) : '""',
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignName() : ''),
                sprintf(
                    '"%s"',
                    $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignSource() : ''
                ),
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getReferer() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignName() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getReferer() : ''),
                sprintf('"%s"', $claim->getInitialSuspicion() ? 'yes' : 'no'),
                sprintf('"%s"', $claim->getFinalSuspicion() ? 'yes' : 'no'),
                sprintf('"%s"', $this->timezoneFormat($claim->getReplacementReceivedDate(), $timezone, 'Y-m-d')),
                sprintf('"%s"', $claim->getHandlingTeam()),
                sprintf('"%0.2f"', $claim->getTotalIncurred()),
                sprintf('"%s"', $this->timezoneFormat($claim->getClosedDate(), $timezone, 'Y-m-d')),
                sprintf(
                    '"%s"',
                    $claim->getFnolRisk() ? $claim->getFnolRisk() : null
                ),
                sprintf('"%s"', $claim->getNetwork()),
                sprintf('"%s"', $phonePolicy ? $phonePolicy->getPhone()->__toString() : '')
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'claims.csv');
        }
        return $lines;
    }

    /**
     * Creates an array in the style of a csv file containing the current data on users.
     * @param boolean      $skipS3   says whether we should upload the created array to S3 storage.
     * @param DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of user data.
     */
    private function exportUsers($skipS3, DateTimeZone $timezone)
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findAllBiUsersBatched(1000);
        $lines = [];
        $lines[] = implode(',', [
            '"Age of User"',
            '"Postcode of User"',
            '"User Id"',
            '"User Created"',
            '"Purchased Policy"',
            '"First Policy Start"',
            '"First Phone Insured"',
            '"First Seen on App"',
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
        ]);
        foreach ($users as $user) {
            $billing = $user->getBillingAddress();
            /** @var User $user */
            $census = $billing ? $this->searchService->findNearest($billing->getPostcode()) : null;
            $income = $billing ? $this->searchService->findIncome($billing->getPostcode()) : null;
            /** @var PhonePolicy $policy */
            $policy = $user->getFirstPolicy();
            $lines[] = implode(',', [
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $billing ? $billing->getPostcode() : ''),
                sprintf('"%s"', $user->getId()),
                sprintf('"%s"', $this->timezoneFormat($user->getCreated(), $timezone, 'Y-m-d')),
                sprintf('"%s"', count($user->getCreatedPolicies()) > 0 ? 'yes' : 'no'),
                $policy? $this->timezoneFormat($policy->getStart(), $timezone, 'Y-m-d'): "",
                $policy? sprintf('%s %s', $policy->getPhone()->getMake(), $policy->getPhone()->getModel()): "",
                $user->getFirstLoginInApp()? $this->timezoneFormat($user->getFirstLoginInApp(), $timezone, 'Y-m-d'): "",
                sprintf('"%s"', $census ? $census->getSubgrp() : ''),
                sprintf('"%s"', $user->getGender() ? $user->getGender() : ''),
                $income ? sprintf('"%0.0f"', $income->getTotal()->getIncome()) : '""',
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignName() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : '')
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'users.csv');
        }
        return $lines;
    }

    /**
     * Creates an array in the style of a csv file containing the current data on invitations.
     * @param boolean      $skipS3   says whether we should upload the created array to S3 storage.
     * @param DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of invitation data.
     */
    private function exportInvitations($skipS3, DateTimeZone $timezone)
    {
        $repo = $this->dm->getRepository(Invitation::class);
        $invitations = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Source Policy"',
            '"Invitation Date"',
            '"Invitation Method"',
            '"Accepted Date"',
        ]);
        foreach ($invitations as $invitation) {
            /** @var Invitation $invitation */
            $lines[] = implode(',', [
                sprintf('"%s"', $invitation->getPolicy() ? $invitation->getPolicy()->getPolicyNumber() : ''),
                sprintf('"%s"', $this->timezoneFormat($invitation->getCreated(), $timezone, 'Y-m-d H:i:s')),
                sprintf('"%s"', $invitation->getChannel()),
                sprintf('"%s"', $this->timezoneFormat($invitation->getAccepted(), $timezone, 'Y-m-d H:i:s'))
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'invitations.csv');
        }
        return $lines;
    }

    /**
     * Creates an array in the style of a csv file containing the current data on connections.
     * @param boolean      $skipS3   says whether we should upload the created array to S3 storage.
     * @param DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of connection data.
     */
    private function exportConnections($skipS3, DateTimeZone $timezone)
    {
        $repo = $this->dm->getRepository(StandardConnection::class);
        $connections = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Source Policy"',
            '"Linked Policy"',
            '"Invitation Date"',
            '"Invitation Method"',
            '"Connection Date"',
        ]);
        foreach ($connections as $connection) {
            /** @var Connection $connection */
            $lines[] = implode(',', [
                sprintf(
                    '"%s"',
                    $connection->getSourcePolicy() ? $connection->getSourcePolicy()->getPolicyNumber() : ''
                ),
                sprintf(
                    '"%s"',
                    $connection->getLinkedPolicy() ? $connection->getLinkedPolicy()->getPolicyNumber() : ''
                ),
                sprintf(
                    '"%s"',
                    $this->timezoneFormat($connection->getInitialInvitationDate(), $timezone, 'Y-m-d H:i:s')
                ),
                sprintf(
                    '"%s"',
                    $connection->getInvitation() ? $connection->getInvitation()->getChannel() : ''
                ),
                sprintf(
                    '"%s"',
                    $this->timezoneFormat($connection->getDate(), $timezone, 'Y-m-d H:i:s')
                )
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'connections.csv');
        }
        return $lines;
    }

    /**
     * Creates an array in the style of a csv file containing the current data on unpaid calls.
     * @param boolean      $skipS3   determines whether the created csv should be uploaded to S3 storage.
     * @param DateTimeZone $timezone is the timezone in which dates should be given.
     * @return array containing first a row of column names, and then rows of unpaid call data.
     */
    private function exportUnpaidCalls($skipS3, DateTimeZone $timezone)
    {
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->createQueryBuilder()->eagerCursor(true)->field('user')->prime(true)
            ->field('notesList.type')->equals('call')
            ->getQuery()->execute();
        $lines = [];
        $lines[] = implode(',', [
            '"Date"',
            '"Name"',
            '"Email"',
            '"PolicyNumber"',
            '"Phone Number"',
            '"Claim"',
            '"Cost of claims"',
            '"Termination Date"',
            '"Days Before Termination"',
            '"Present status"',
            '"Call"',
            '"Note"',
            '"Voicemail"',
            '"Other Actions"',
            '"All actions"',
            '"Category"',
            '"Termination week number"',
            '"Call week number"',
            '"Call month"',
            '"Cancellation month"'
        ]);
        foreach ($policies as $policy) {
            $note = $policy->getLatestNoteByType(Note::TYPE_CALL);
            $approvedClaims = $policy->getApprovedClaims(true);
            $claimsCost = 0;
            foreach ($approvedClaims as $approvedClaim) {
                /** @var Claim $approvedClaim */
                $claimsCost += $approvedClaim->getTotalIncurred();
            }
            $lines[] = implode(',', [
                sprintf('"%s"', $note->getDate()->format('Y-m-d')),
                sprintf('"%s"', $policy->getUser()->getName()),
                sprintf('"%s"', $policy->getUser()->getEmail()),
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%s"', $policy->getUser()->getMobileNumber()),
                sprintf('"%s"', count($approvedClaims)),
                sprintf('"%s"', $claimsCost),
                sprintf(
                    '"%s"',
                    $policy->getPolicyExpirationDate() ? $policy->getPolicyExpirationDate()->format('Y-m-d') : null
                ),
                sprintf('"%s"', 'FORMULA'),
                sprintf('"%s"', $policy->getStatus()),
                sprintf('"%s"', 'Yes'),
                sprintf('"%s"', $note->getResult()),
                sprintf('"%s"', $note->getVoicemail() ? 'Yes' : ''),
                sprintf('"%s"', $note->getOtherActions()),
                sprintf('"%s"', $note->getActions(true)),
                sprintf('"%s"', $note->getCategory()),
                sprintf(
                    '"%s"',
                    $policy->getPolicyExpirationDate() ? $policy->getPolicyExpirationDate()->format('W') : null
                ),
                sprintf('"%s"', $note->getDate()->format('W')),
                sprintf('"%s"', $note->getDate()->format('M')),
                sprintf(
                    '"%s"',
                    $policy->getPolicyExpirationDate() ? $policy->getPolicyExpirationDate()->format('M') : null
                )
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'unpaidCalls.csv');
        }
        return $lines;
    }

    /**
     * Exports rewards distribution data per month
     * @param boolean $skipS3 tells you whether to skip uploading the file to s3.
     * @return array list of generated lines.
     */
    private function exportRewards($skipS3)
    {
        /** @var RewardRepository */
        $rewardRepo = $this->dm->getRepository(Reward::class);

        //Initialise data arrays
        $headers = [];
        $data = [];

        //Initialise csv lines array
        $lines = [];

        //Set total variables
        $monthTotal = ['Total','','',''];

        //initialise time period for budget
        $end = strtotime(date("Y-m"));
        $current = $start = strtotime("-11 month", $end);

        //Set csv header
        $headers[] = "Code";
        $headers[] = "Code Category";
        $headers[] = "Target";
        $headers[] = "Default Value";
        while ($current <= $end) {
            $headers[] = date('F-Y', $current);
            $current = strtotime("+1 month", $current);
        }
        $current = $start;

        //Get Codes and sort by Category
        $rewards = $rewardRepo->findBy([], ['type'=>'DESC']);

        //Generate data
        foreach ($rewards as $key => $reward) {
            $data[$key][0] = $reward->getScode() ? $reward->getScode()->getCode() : $reward->getUser()->getEmail();
            $data[$key][1] = $reward->getType() ? $reward->getType() : "n/a";
            $data[$key][2] = $reward->getTarget() ? $reward->getTarget() : "n/a";
            $data[$key][3] = $reward->getDefaultValue() ? $reward->getDefaultValue() : "Custom";
            $connections = $reward->getConnections();
            $cm = 4;
            while ($current <= $end) {
                if (!isset($data[$key][$cm])) {
                    $data[$key][$cm]=0;
                }
                if (!isset($monthTotal[$cm])) {
                    $monthTotal[$cm]=0;
                }
                foreach ($connections as $connection) {
                    $connectionDate = $connection->getDate()->format('Ym');
                    $currentStr = date('Ym', $current);
                    if ($connectionDate === $currentStr) {
                            $data[$key][$cm] += 1;
                            $monthTotal[$cm] += 1;
                    }
                }
                $current = strtotime("+1 month", $current);
                $cm++;
            }
            $current = $start;
        }

        //Transform data arrays tp csv lines
        $lines[] = CsvHelper::line(...$headers);
        foreach ($data as $line) {
            $lines[] = CsvHelper::line(...$line);
        }
        $lines[] = CsvHelper::line(...$monthTotal);

        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'rewards.csv');
        }

        return $lines;

    }

    /**
     * Exports rewards previsionnal budget data as well as reward code use analytics to a csv file.
     * @param boolean $skipS3 tells you whether to skip uploading the file to s3.
     * @return array list of generated lines.
     */
    private function exportRewardsBudget($skipS3)
    {

        /** @var RewardRepository */
        $rewardRepo = $this->dm->getRepository(Reward::class);

        //Initialise data arrays
        $headers = [];
        $data = [];

        //Set total variables
        $monthPolicies = [];
        $monthBudget = [];
        $cpa = [];

        //Initialise csv lines array
        $lines = [];

        //initialise time period for budget
        $start = $current =  strtotime(date("Y-m"));
        $end = strtotime("+24 month", $start);

        //Set csv header
        $headers[] = "Code Category";
        while ($current < $end) {
            $headers[] = date('F-Y', $current);
            $current = strtotime("+1 month", $current);
        }
        $current = $start;

        //Get all code categories
        $categories = $this->dm->createQueryBuilder(Reward::class)
            ->distinct('type')
            ->getQuery()
            ->execute();
        $categories[] = "n/a";

        foreach ($categories as $key => $category) {
            if ($category == "n/a") {
                $rewards = $this->dm->createQueryBuilder(Reward::class)
                    ->field('type')
                    ->exists(false)
                    ->getQuery()
                    ->execute()
                    ->toArray();
            } else {
                $rewards = $rewardRepo->findBy(['type' => $category]);
            }
            if ($category == "") {
                $category = "n/a";
            }
            if (count($rewards)) {
                $data[$key][0] = $category;
                $monthBudget[0] = "Total";
                $monthPolicies[0] = "Policies";
                $cpa[0] = "CPA";
            }
            foreach ($rewards as $reward) {
                $connections = $reward->getConnections();
                $cm = 1;
                while ($current < $end) {
                    $value = 0;
                    if (!isset($monthPolicies[$cm])) {
                        $monthPolicies[$cm]=0;
                    }
                    if (!isset($monthBudget[$cm])) {
                        $monthBudget[$cm]=0;
                    }
                    if (!isset($cpa[$cm])) {
                        $cpa[$cm]=0;
                    }
                    foreach ($connections as $connection) {
                        $policy = $connection->getSourcePolicy();
                        if ($policy->isActive() && !$policy->hasMonetaryClaimed()) {
                            $policyend = $policy->getEnd()->format('Ym');
                            $currentStr = date('Ym', $current);
                            if ($policyend === $currentStr) {
                                $value += $connection->getPromoValue();
                                $monthBudget[$cm] += $connection->getPromoValue();
                                $monthPolicies[$cm] += 1;
                                $cpa[$cm] = $monthBudget[$cm]/$monthPolicies[$cm];
                            }
                        }
                    }
                    if (isset($data[$key][$cm])) {
                        $data[$key][$cm] += $value;
                    } else {
                        $data[$key][$cm] = $value;
                    }
                    $current = strtotime("+1 month", $current);
                    $cm++;
                }
                $current = $start;
            }
        }

        //Transform data arrays in csv lines
        $lines[] = CsvHelper::line(...$headers);
        foreach ($data as $line) {
            foreach ($line as $key => $val) {
                if (!$key==0) {
                    $line[$key] = number_format(floatval($val), 2, '.', ',');
                }
            }
            $lines[] = CsvHelper::line(...$line);
        }
        foreach ($monthBudget as $key => $val) {
            if (!$key==0) {
                $monthBudget[$key] = number_format(floatval($val), 2, '.', ',');
            }
        }
        foreach ($cpa as $key => $val) {
            if (!$key==0) {
                $cpa[$key] = number_format(floatval($val), 2, '.', ',');
            }
        }
        $lines[] = CsvHelper::line(...$monthBudget);
        $lines[] = CsvHelper::line(...$monthPolicies);
        $lines[] = CsvHelper::line(...$cpa);

        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'rewardsbudget.csv');
        }

        return $lines;
    }

    /**
     * Exports currently relevant bacs payments. The definition of relevant is that their effect date is greater than
     * three weeks ago or their status is pending.
     * @param boolean $skipS3 is whether to skip uploading the results to s3.
     * @return array containing all the generated lines.
     */
    private function exportBacsPayments($skipS3)
    {
        /** @var BacsPaymentRepository $bacsPaymentRepo */
        $bacsPaymentRepo = $this->dm->getRepository(BacsPayment::class);
        $payments = $bacsPaymentRepo->findBacsPaymentsForReport(new DateTime());
        $lines = [];
        $lines[] = CsvHelper::line(
            'policyNumber',
            'id',
            'date',
            'created',
            'amount',
            'status',
            'notes'
        );
        foreach ($payments as $payment) {
            $lines[] = CsvHelper::line(
                $payment->getPolicy()->getPolicyNumber(),
                $payment->getDate()->format('Ymd H:i'),
                $payment->getCreated()->format('Ymd H:i'),
                $payment->getAmount(),
                $payment->getStatus(),
                $payment->getNotes()
            );
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'bacsPayments.csv');
        }
        return $lines;
    }

    /**
     * Exports the referral bonuses
     * @param boolean $skipS3 tells you whether to skip uploading the file to s3.
     * @return array list of generated lines.
     */
    private function exportReferrals($skipS3)
    {
        $rewardRepo = $this->dm->getRepository(ReferralBonus::class);

        //Initialise data arrays
        $headers = [];
        $data = [];

        //Initialise csv lines array
        $lines = [];

        //Set csv header
        $lines[] = implode(',', [
            "Created",
            "Inviter",
            "Inviter Bonus Value",
            "Applied to Inviter",
            "Invitee",
            "Invitee Bonus Value",
            "Applied to Invitee",
            "Status"
        ]);

        //Get Referral bonuses
        $referrals = $rewardRepo->findBy([], ['created'=>'DESC']);

        //Generate data
        foreach ($referrals as $key => $referral) {
            $lines[] = implode(',', [
                sprintf('"%s"', $referral->getCreated()->format('Y-m-d')),
                sprintf('"%s"', $referral->getInviter()->getId()),
                sprintf('"%s"', $referral->getAmountForInviter()),
                sprintf('"%s"', $referral->getInviterPaid()?"Yes":"No"),
                sprintf('"%s"', $referral->getInvitee()->getId()),
                sprintf('"%s"', $referral->getAmountForInvitee()),
                sprintf('"%s"', $referral->getInviteePaid()?"Yes":"No"),
                sprintf('"%s"', $referral->getStatus())
            ]);
        }

        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'referrals.csv');
        }
        return $lines;

    }

    /**
     * Uploads an array of data to s3 as lines in a file.
     * @param string $data     is the data to write to the file.
     * @param string $filename is the name of the file to write it to.
     * @return string the key to the file on s3.
     */
    private function uploadS3($data, $filename)
    {
        /**
         * It is possible that the filename can be a path, but the permissions on prod do not
         * allow for a subdir to be created but S3 does.
         * This means that there can be instances where the cron will fail.
         * Solution is that if a path is passed in, explode it and take the last part
         * for the tmp filename, but still use the full filename with path for s3.
         */
        $tmpFilename = $filename;
        if (mb_strpos($filename, '/') !== false) {
            $parts = explode('/', $filename);
            $tmpFilename = array_pop($parts);
        }
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $tmpFilename);
        $result = file_put_contents($tmpFile, $data);
        if (!$result) {
            $this->logger->error($filename.' could not be processed into a tmp file.');
            return '';
        }
        $s3Key = sprintf('%s/bi/%s', $this->environment, $filename);
        $this->s3->putObject([
            'Bucket' => SoSure::S3_BUCKET_ADMIN,
            'Key' => $s3Key,
            'SourceFile' => $tmpFile
        ]);
        unlink($tmpFile);
        return $s3Key;
    }
}
