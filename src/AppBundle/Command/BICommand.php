<?php

namespace AppBundle\Command;

use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Note\Note;
use AppBundle\Document\Connection\Connection;
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
use AppBundle\Document\Lead;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\RewardRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use Aws\S3\S3Client;
use CensusBundle\Service\SearchService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\SoSure;

/**
 * Command for exporting CSV reports on various collections of company data.
 */
class BICommand extends ContainerAwareCommand
{
    use DateTrait;

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
        $onlyOptions = [
            "policies",
            "claims",
            "users",
            "invitations",
            "connections",
            "phones",
            "unpaidCalls",
            "leadSource",
            "checkoutTransactions",
            "scodes",
            "rewards",
            "rewardsBudget"
        ];
        $onlyMessage = "Only run one export [" . implode(', ', $onlyOptions) . "]";
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
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                $onlyMessage
            )
            ->addOption(
                'skip-s3',
                null,
                InputOption::VALUE_NONE,
                'Skip s3 upload'
            )
            ->addOption(
                "timezone",
                null,
                InputOption::VALUE_REQUIRED,
                "Choose a timezone to use for policies report"
            )
            ->addOption(
                "date",
                null,
                InputOption::VALUE_REQUIRED,
                "Set the date you want to query transactions for - MM/YY."
            )
        ;
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $debug = $input->getOption('debug');
        $prefix = $input->getOption('prefix');
        $only = $input->getOption('only');
        $skipS3 = true === $input->getOption('skip-s3');
        $timezone = new \DateTimeZone($input->getOption('timezone') ?: 'UTC');
        $lines = [];
        if (!$only || $only == 'policies') {
            $lines = $this->exportPolicies($prefix, $skipS3, $timezone);
        }
        if (!$only || $only == 'claims') {
            $lines = $this->exportClaims($skipS3, $timezone);
        }
        if (!$only || $only == 'users') {
            $lines = $this->exportUsers($skipS3, $timezone);
        }
        if (!$only || $only == 'invitations') {
            $lines = $this->exportInvitations($skipS3, $timezone);
        }
        if (!$only || $only == 'connections') {
            $lines = $this->exportConnections($skipS3, $timezone);
        }
        if (!$only || $only == 'phones') {
            $lines = $this->exportPhones($skipS3, $timezone);
        }
        if (!$only || $only == 'unpaidCalls') {
            $lines = $this->exportUnpaidCalls($skipS3, $timezone);
        }
        if (!$only || $only == 'leadSource') {
            $lines = $this->exportLeadSource($skipS3, $timezone);
        }
        if (!$only || $only == 'checkoutTransactions') {
            $date = $input->getOption('date');
            $lines = $this->exportCheckoutTransactions($skipS3, $timezone, $date);
        }
        if (!$only || $only == 'scodes') {
            $lines = $this->exportScodes($skipS3, $timezone);
        }
        if (!$only || $only == 'rewards') {
            $lines = $this->exportRewards($skipS3);
        }
        if (!$only || $only == 'rewardsBudget') {
            $lines = $this->exportRewardsBudget($skipS3);
        }
        if ($debug) {
            foreach ($lines as $line) {
                $output->writeln($line);
            }
        }
    }

    /**
     * Creates an array in the style of a csv file containing the current data on phones.
     * @param boolean       $skipS3   says whether we should upload the created array to S3 storage.
     * @param \DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of phone data.
     */
    private function exportPhones($skipS3, \DateTimeZone $timezone)
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
            $lines[] = implode(',', [
                sprintf('"%s"', $phone->getMake()),
                sprintf('"%s"', $phone->getModel()),
                sprintf('"%s"', $phone->getMemory()),
                sprintf('"%0.2f"', $phone->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice()),
                sprintf('"%0.2f"', $phone->getCurrentYearlyPhonePrice()->getYearlyPremiumPrice()),
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
     * @param boolean       $skipS3   says whether we should upload the created array to S3 storage.
     * @param \DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of claim data.
     */
    private function exportClaims($skipS3, \DateTimeZone $timezone)
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
            $census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode());
            $income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode());
            $lines[] = implode(',', [
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%s"', $this->timezoneFormat($policy->getStart(), $timezone, 'Y-m-d H:i:s')),
                sprintf('"%s"', $this->timezoneFormat($claim->getNotificationDate(), $timezone, 'Y-m-d H:i:s')),
                sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
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
     * Creates an array in the style of a csv file containing the current data on policies.
     * @param string        $prefix   is the policy prefix for which we are checking on policies.
     * @param boolean       $skipS3   says whether we should upload the created array to S3 storage.
     * @param \DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of policy data.
     */
    private function exportPolicies($prefix, $skipS3, \DateTimeZone $timezone)
    {
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        /** @var RewardRepository $rewardRepo */
        $rewardRepo = $this->dm->getRepository(Reward::class);
        $policies = $phonePolicyRepo->findAllStartedPolicies($prefix, new \DateTime(SoSure::POLICY_START))->toArray();
        $lines = [];
        $lines[] = $this->makeLine(
            'Policy Number',
            'Policy Holder Id',
            'Age of Policy Holder',
            'Postcode of Policy Holder',
            'Pen Portrait',
            'Gender',
            'Total Weekly Income',
            'Make',
            'Make/Model',
            'Make/Model/Memory',
            'Policy Start Date',
            'Policy End Date',
            'Premium Installments',
            'First Time Policy',
            'Policy Number Prior Renewal',
            'Policy Number Renewal',
            'Policy Result of Upgrade',
            'This Policy is the X renewal',
            'Policy Status',
            'Expected Unpaid Cancellation Date',
            'Policy Cancellation Reason',
            'Requested Cancellation (Phone Damaged Prior to Policy)',
            'Requested Cancellation Reason (Phone Damaged Prior to Policy)',
            'Invitations',
            'Connections',
            'Reward Pot',
            'Pic-Sure Status',
            'Total Number of Claims',
            'Number of Approved/Settled Claims',
            'Number of Withdrawn/Declined Claims',
            'Policy Purchase Time',
            'Lead Source',
            'First Scode Type',
            'First Scode Name',
            'All SCodes Used',
            'Promo Codes',
            'Has Sign-up Bonus?',
            'Latest Campaign Source (user)',
            'Latest Campaign Name (user)',
            'Latest referer (user)',
            'First Campaign Source (user)',
            'First Campaign Name (user)',
            'First referer (user)',
            'Purchase SDK',
            'Payment Method',
            'Bacs Mandate Status',
            'Bacs Mandate Cancelled Reason',
            'Successful Payment',
            'Latest Payment Failed Without Reschedule',
            'Yearly Premium',
            'Premium Paid',
            'Premium Outstanding',
            'Past Due Amount (Bad Debt Only)',
            'Company of Policy'
        );
        foreach ($policies as $policy) {
            if ($policy->getEnd() <= $policy->getStart()) {
                continue;
            }
            $connections = $policy->getConnections();
            $user = $policy->getUser();
            $previous = $policy->getPreviousPolicy();
            $next = $policy->getNextPolicy();
            $phone = $policy->getPhone();
            $census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode());
            $income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode());
            $attribution = $user->getAttribution();
            $latestAttribution = $user->getLatestAttribution();
            $bankAccount = $policy->getPolicyOrUserBacsBankAccount();
            $scodeType = $this->getFirstSCodeUsedType($rewardRepo, $connections);
            $scodeName = $this->getFirstSCodeUsedCode($connections);
            $reschedule = null;
            $lastReverted = $policy->getLastRevertedScheduledPayment();
            if ($lastReverted) {
                $reschedule = $scheduledPaymentRepo->getRescheduledBy($lastReverted);
            }
            $company = $policy->getCompany();
            $lines[] = $this->makeLine(
                $policy->getPolicyNumber(),
                $user->getId(),
                $user->getAge(),
                $user->getBillingAddress()->getPostcode(),
                $census ? $census->getSubgrp() : '',
                $user->getGender() ?: '',
                $income ? sprintf('%0.0f', $income->getTotal()->getIncome()) : '',
                $phone->getMake(),
                sprintf('%s %s', $phone->getMake(), $phone->getModel()),
                $phone,
                $this->timezoneFormat($policy->getStart(), $timezone, 'Y-m-d'),
                $this->timezoneFormat($policy->getEnd(), $timezone, 'Y-m-d'),
                $policy->getPremiumInstallments(),
                $policy->useForAttribution() ? 'yes' : 'no',
                $previous ? $previous->getPolicyNumber() : '',
                $next ? $next->getPolicyNumber() : '',
                $this->getPreviousPolicyIsUpgrade($policy),
                $policy->getGeneration(),
                $policy->getStatus(),
                $policy->getStatus() == Policy::STATUS_UNPAID ?
                    $this->timezoneFormat($policy->getPolicyExpirationDate(), $timezone, 'Y-m-d') : '',
                $policy->getStatus() == Policy::STATUS_CANCELLED ? $policy->getCancelledReason() : '',
                $policy->hasRequestedCancellation() ? 'yes' : 'no',
                $policy->getRequestedCancellationReason() ?: '',
                count($policy->getInvitations()),
                count($policy->getStandardConnections()),
                $policy->getPotValue(),
                $policy->getPicsureStatus() ?: 'unstarted',
                count($policy->getClaims()),
                count($policy->getApprovedClaims()),
                count($policy->getWithdrawnDeclinedClaims()),
                $this->timezoneFormat($policy->getStart(), $timezone, 'H:i'),
                $policy->getLeadSource(),
                $scodeType,
                $scodeName,
                $this->getSCodesUsed($connections),
                $this->getPromoCodesUsed($rewardRepo, $connections),
                $this->policyHasSignUpBonus($rewardRepo, $connections) ? 'yes' : 'no',
                $latestAttribution ? $latestAttribution->getCampaignSource() : '',
                $latestAttribution ? $latestAttribution->getCampaignName() : '',
                $latestAttribution ? $latestAttribution->getReferer() : '',
                $attribution ? $attribution->getCampaignSource() : '',
                $attribution ? $attribution->getCampaignName() : '',
                $attribution ? $attribution->getReferer() : '',
                $policy->getPurchaseSdk(),
                $policy->getUsedPaymentType(),
                ($bankAccount && $policy->isActive()) ? $bankAccount->getMandateStatus() : '',
                ($bankAccount && $policy->isActive() &&
                    $bankAccount->getMandateStatus() == BankAccount::MANDATE_CANCELLED) ?
                    $bankAccount->getMandateCancelledExplanation() : '',
                count($policy->getSuccessfulUserPaymentCredits()) > 0 ? 'yes' : 'no',
                ($lastReverted && !$reschedule) ? 'yes' : 'no',
                $policy->getPremium()->getYearlyPremiumPrice(),
                $policy->getPremiumPaid(),
                $policy->getUnderwritingOutstandingPremium(),
                $policy->getBadDebtAmount(),
                $company ? $company->getName() : ''
            );
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'policies.csv');
        }
        return $lines;
    }

    /**
     * Creates an array in the style of a csv file containing the current data on users.
     * @param boolean       $skipS3   says whether we should upload the created array to S3 storage.
     * @param \DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of user data.
     */
    private function exportUsers($skipS3, \DateTimeZone $timezone)
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
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
        ]);
        foreach ($users as $user) {
            /** @var User $user */
            $census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode());
            $income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode());
            $lines[] = implode(',', [
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
                sprintf('"%s"', $user->getId()),
                sprintf('"%s"', $this->timezoneFormat($user->getCreated(), $timezone, 'Y-m-d')),
                sprintf('"%s"', count($user->getCreatedPolicies()) > 0 ? 'yes' : 'no'),
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
     * @param boolean       $skipS3   says whether we should upload the created array to S3 storage.
     * @param \DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of invitation data.
     */
    private function exportInvitations($skipS3, \DateTimeZone $timezone)
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
     * @param boolean       $skipS3   says whether we should upload the created array to S3 storage.
     * @param \DateTimeZone $timezone is the timezone to give dates in.
     * @return array containing first a row of column names and then rows of connection data.
     */
    private function exportConnections($skipS3, \DateTimeZone $timezone)
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
     * @param boolean       $skipS3   determines whether the created csv should be uploaded to S3 storage.
     * @param \DateTimeZone $timezone is the timezone in which dates should be given.
     * @return array containing first a row of column names, and then rows of unpaid call data.
     */
    private function exportUnpaidCalls($skipS3, \DateTimeZone $timezone)
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

    private function exportLeadSource($skipS3, \DateTimeZone $timezone)
    {
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        /** @var InvitationRepository $invitationRepo */
        $invitationRepo = $this->dm->getRepository(Invitation::class);
        $policies = $policyRepo->createQueryBuilder()
            ->field('leadSource')->in(['scode', 'invitation'])
            ->getQuery()->execute();
        $lines = [];
        $lines[] = implode(',', [
            '"Inviter"',
            '"Invitee"',
            '"Inverted"'
        ]);
        foreach ($policies as $policy) {
            $inverted = false;
            $other = null;
            /** @var Invitation $invitation */
            $invitation = $invitationRepo->getOwnInvitation($policy);
            if (!$invitation) {
                $inverted = true;
                $invitation = $invitationRepo->getFirstMadeInvitation($policy);
                if ($invitation) {
                    $invitee = $invitation->getInvitee();
                    if ($invitee) {
                        $other = $invitee->getLatestPolicy();
                    }
                }
            } else {
                $other = $invitation->getPolicy();
            }
            $lines[] = implode(',', [
                sprintf('"%s"', $other ? $other->getPolicyNumber() : 'N/A'),
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%s"', $inverted ? "Yes" : "No")
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'leadSource.csv');
        }
        return $lines;
    }

    private function exportCheckoutTransactions($skipS3, \DateTimeZone $timezone, $date)
    {
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(Payment::class);

        if ($date === null) {
            $date = date('Y-m');
        }
        $now = new \DateTime($date);
        $nextMonth = new \DateTime($date);
        $nextMonth->add(new \DateInterval('P1M'));

        $transactions = $repo->createQueryBuilder()
            ->field('date')->gte($now)
            ->field('date')->lt($nextMonth)
            ->field('type')->equals('checkout')
            ->sort('created', 1)
            ->getQuery()->execute();


        $lines = [];
        $lines[] = $this->makeLine(
            "Date",
            "Payment ID",
            "Transaction ID",
            "Result",
            "Policy Number",
            "Message",
            "Details",
            "Detailed Info",
            "Response Code"
        );

        /** @var CheckoutPayment $transaction */
        foreach ($transactions as $transaction) {
            /** @var Policy $policy */
            $policy = $transaction->getPolicy();

            $lines[] = $this->makeLine(
                $transaction->getDate()->format('jS M Y H:i'),
                $transaction->getId(),
                $transaction->getReceipt(),
                $transaction->getResult(),
                $policy->getPolicyNumber(),
                $transaction->getMessage(),
                $transaction->getDetails(),
                $transaction->getInfo(),
                $transaction->getResponseCode()
            );
        }
        if (!$skipS3) {
            $fileName = $now->format('Y') . '/' . $now->format('m') . '/' . 'checkOutTransactions.csv';
            $this->uploadS3(implode(PHP_EOL, $lines), $fileName);
        }
        return $lines;
    }

    /**
     * Gives you a list of lines in a csv containing all scodes used by all users.
     * @param boolean       $skipS3   tells you whether to skip uploading the file to s3.
     * @param \DateTimeZone $timezone is the timezone we are getting dates in.
     */
    private function exportScodes($skipS3, \DateTimeZone $timezone)
    {
        /** @var PolicyRepository */
        $policyRepo = $this->dm->getRepository(Policy::class);
        /** @var ConnectionRepository */
        $connectionRepo = $this->dm->getRepository(Connection::class);
        $policies = $policyRepo->findScodePolicies();
        $lines = [];
        $lines[] = $this->makeLine(
            "Scode",
            "Scode Type",
            "User Id",
            "Policy Number",
            "Date"
        );
        foreach ($policies as $policy) {
            foreach ($policy->getScodes() as $scode) {
                $type = $scode->getType();
                $connection = null;
                if ($type == Scode::TYPE_REWARD) {
                    $rewardUser = $scode->getReward()->getUser();
                    $connection = $connectionRepo->findByUser($rewardUser, $policy);
                } elseif ($type == Scode::TYPE_STANDARD) {
                    $other = $scode->getPolicy();
                    $connection = $connectionRepo->connectedByPolicy($policy, $other);
                }
                if ($connection) {
                    $lines[] = $this->makeLine(
                        $scode->getCode(),
                        $scode->getType(),
                        $policy->getUser()->getId(),
                        $policy->getPolicyNumber(),
                        $connection->getDate()->format("Y-m-d H:i")
                    );
                }
            }
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'scodes.csv');
        }
        return $lines;
    }

    /**
     * Exports rewards distribution data per month
     * @param boolean $skipS3 tells you whether to skip uploading the file to s3.
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
        $monthTotal = ['Total','',''];

        //initialise time period for budget
        $end = strtotime(date("Y-m"));
        $current = $start = strtotime("-11 month", $end);

        //Set csv header
        $headers[] = "Code";
        $headers[] = "Code Category";
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
            $data[$key][2] = $reward->getDefaultValue() ? $reward->getDefaultValue() : "Custom";
            $connections = $reward->getConnections();
            $cm = 3;
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
        $lines[] = $this->makeLine(...$headers);
        foreach ($data as $line) {
            $lines[] = $this->makeLine(...$line);
        }
        $lines[] = $this->makeLine(...$monthTotal);

        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'rewards.csv');
        }

        return $lines;

    }

    /**
     * Exports rewards previsionnal budget data as well as reward code use analytics to a csv file.
     * @param boolean $skipS3 tells you whether to skip uploading the file to s3.
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
        $lines[] = $this->makeLine(...$headers);
        foreach ($data as $line) {
            foreach ($line as $key => $val) {
                if (!$key==0) {
                    $line[$key] = "£" . number_format(floatval($val), 2, '.', ',');
                }
            }
            $lines[] = $this->makeLine(...$line);
        }
        foreach ($monthBudget as $key => $val) {
            if (!$key==0) {
                $monthBudget[$key] = "£" . number_format(floatval($val), 2, '.', ',');
            }
        }
        foreach ($cpa as $key => $val) {
            if (!$key==0) {
                $cpa[$key] = "£" . number_format(floatval($val), 2, '.', ',');
            }
        }
        $lines[] = $this->makeLine(...$monthBudget);
        $lines[] = $this->makeLine(...$monthPolicies);
        $lines[] = $this->makeLine(...$cpa);

        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'rewardsbudget.csv');
        }

        return $lines;
    }

    private function getFirstSCodeUsedType(RewardRepository $rewardRepo, $connections)
    {
        $oldest = new \DateTime();
        $firstConnection = new \stdClass();
        /** @var Connection $connection */
        foreach ($connections as $connection) {
            $signUp = false;
            if ($connection instanceof RewardConnection) {
                $signUp = $this->isSignUpBonusSCode($rewardRepo, $connection);
            }

            if (($connection->getDate() < $oldest) && !$signUp) {
                $oldest = $connection->getDate();
                $firstConnection = $connection;
            }
        }
        $retVal = "";
        if ($firstConnection instanceof RewardConnection) {
            $retVal = "reward";
        } elseif ($firstConnection instanceof StandardConnection) {
            $retVal = "virality";
        } elseif ($firstConnection instanceof RenewalConnection) {
            $retVal = "renewal";
        }
        return $retVal;
    }

    private function getSCodesUsed($connections)
    {
        $retVal = "";
        /** @var Connection $connection */
        foreach ($connections as $connection) {
            if (!$connection instanceof RewardConnection) {
                if ($connection->getLinkedPolicy() instanceof Policy) {
                    $retVal .= $connection->getLinkedPolicy()->getStandardSCode()->getCode() . ';';
                }
            }
        }
        return $retVal;
    }

    private function getPromoCodesUsed(RewardRepository $rewardRepo, $connections)
    {
        $retVal = "";
        /** @var Connection $connection */
        foreach ($connections as $connection) {
            if ($connection instanceof RewardConnection) {
                $rewards = $rewardRepo->findBy(['user.id' => $connection->getLinkedUser()->getId()]);
                /** @var Reward $reward */
                foreach ($rewards as $reward) {
                    if ($reward->getSCode()) {
                        $retVal .= $reward->getSCode()->getCode() . ';';
                    } else {
                        $retVal .= 'BONUS;';
                    }
                }
            }
        }
        return $retVal;
    }

    private function isSignUpBonusSCode(RewardRepository $rewardRepo, $connection)
    {
        $rewards = $rewardRepo->findBy(['user.id' => $connection->getLinkedUser()->getId()]);
        /** @var Reward $reward */
        foreach ($rewards as $reward) {
            if ($reward->getSCode()) {
                return false;
            }
        }
        return true;
    }

    private function getFirstSCodeUsedCode($connections)
    {
        $oldest = new \DateTime();
        $firstConnection = new \stdClass();
        /** @var Connection $connection */
        foreach ($connections as $connection) {
            if ($connection->getDate() < $oldest) {
                $oldest = $connection->getDate();
                $firstConnection = $connection;
            }
        }
        if ($firstConnection instanceof Connection) {
            /** @var Policy $linkedPolicy */
            $linkedPolicy = $firstConnection->getLinkedPolicy();
            if ($linkedPolicy instanceof Policy) {
                $scode = $linkedPolicy->getStandardSCode();
                if ($scode instanceof SCode) {
                    return $linkedPolicy->getStandardSCode()->getCode();
                }
            }
        }
        return "";
    }

    public function policyHasSignUpBonus(RewardRepository $rewardRepo, $connections)
    {
        foreach ($connections as $connection) {
            if ($connection instanceof RewardConnection && $this->isSignUpBonusSCode($rewardRepo, $connection)) {
                return true;
            }
        }
        return false;
    }

    public function getPreviousPolicyIsUpgrade(Policy $policy)
    {
        $user = $policy->getUser();
        $previousPolicies = $user->getPolicies();
        $startWithoutTime = '';
        if ($policy->getStart()) {
            $startWithoutTime = $policy->getStart()->format('Ymd');
        }
        /** @var Policy $previousPolicy */
        foreach ($previousPolicies as $previousPolicy) {
            $previousEndWithoutTime = '';
            if ($previousPolicy->getEnd()) {
                $previousEndWithoutTime = $previousPolicy->getEnd()->format('Ymd');
            }
            if ($previousEndWithoutTime == $startWithoutTime) {
                $cancelled = $previousPolicy->isCancelled();
                $isUpgrade = $previousPolicy->getCancelledReason() == Policy::CANCELLED_UPGRADE;
                if ($cancelled && $isUpgrade) {
                    return 'Yes';
                }
            }
        }
        return 'No';
    }

    /**
     * Uploads an array of data to s3 as lines in a file.
     * @param array  $data     is the data to write to the file.
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
            throw new \Exception($filename . ' could not be processed into a tmp file.');
        }
        $s3Key = sprintf('%s/bi/%s', $this->environment, $filename);
        $result = $this->s3->putObject(array(
            'Bucket' => SoSure::S3_BUCKET_ADMIN,
            'Key'    => $s3Key,
            'SourceFile' => $tmpFile,
        ));
        unlink($tmpFile);
        return $s3Key;
    }

    /**
     * Makes a line of the csv with quotes around the items and commas between them.
     * @param mixed ...$item are all of the string items to concatenate of variable number.
     */
    private function makeLine(...$item)
    {
        return '"'.implode('","', func_get_args()).'"';
    }
}
