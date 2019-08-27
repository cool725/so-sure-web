<?php

namespace AppBundle\Command;

use AppBundle\Document\Note\Note;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
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
            "checkoutTransactions"
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
        if (!$only || $only == 'policies') {
            $lines = $this->exportPolicies($prefix, $skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'claims') {
            $lines = $this->exportClaims($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'users') {
            $lines = $this->exportUsers($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'invitations') {
            $lines = $this->exportInvitations($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'connections') {
            $lines = $this->exportConnections($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'phones') {
            $lines = $this->exportPhones($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'unpaidCalls') {
            $lines = $this->exportUnpaidCalls($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'leadSource') {
            $lines = $this->exportLeadSource($skipS3, $timezone);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
            }
        }
        if (!$only || $only == 'checkoutTransactions') {
            $date = $input->getOption('date');
            $lines = $this->exportCheckoutTransactions($skipS3, $timezone, $date);
            if ($debug) {
                $output->write(json_encode($lines, JSON_PRETTY_PRINT));
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
            '"Original Retail Price"',
            '"Current Retail Price"'
        ]);
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $lines[] = implode(',', [
                sprintf('"%s"', $phone->getMake()),
                sprintf('"%s"', $phone->getModel()),
                sprintf('"%s"', $phone->getMemory()),
                sprintf('"%0.2f"', $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice()),
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
        /** @var InvitationRepository */
        $invitationRepo = $this->dm->getRepository(Invitation::class);
        /** @var ScheduledPaymentRepository */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        /** @var PhonePolicyRepository */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
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
            'Policy Number # Prior Renewal',
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
            'Scode Type',
            'Scode Name',
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
            'Past Due Amount (Bad Debt Only)'
        );
        foreach ($policies as $policy) {
            $user = $policy->getUser();
            $previous = $policy->getPreviousPolicy();
            $next = $policy->getNextPolicy();
            $phone = $policy->getPhone();
            $census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode());
            $income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode());
            $attribution = $user->getAttribution();
            $latestAttribution = $user->getLatestAttribution();
            $bankAccount = $policy->getPolicyOrUserBacsBankAccount();
            $scode = '';
            $scodeType = '';
            if ($policy->getLeadSource() == Lead::LEAD_SOURCE_SCODE) {
                $scodeObject = $policy->getFirstScode();
                if ($scodeObject) {
                    $scode = $scodeObject->getCode();
                    $scodeType = $scodeObject->getType();
                }
            }
            $reschedule = null;
            $lastReverted = $policy->getLastRevertedScheduledPayment();
            if ($lastReverted) {
                $reschedule = $scheduledPaymentRepo->getRescheduledBy($lastReverted);
            }
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
                $scode,
                $latestAttribution ? $latestAttribution->getCampaignSource() : '',
                $latestAttribution ? $latestAttribution->getCampaignName() : '',
                $latestAttribution ? $latestAttribution->getReferer() : '',
                $attribution ? $attribution->getCampaignSource() : '',
                $attribution ? $attribution->getCampaignName() : '',
                $attribution ? $attribution->getReferer() : '',
                $policy->getPurchaseSdk(),
                $policy->getUsedPaymentType(),
                ($bankAccount && $policy->isActive(true)) ? $bankAccount->getMandateStatus() : '',
                ($bankAccount && $policy->isActive(true) &&
                    $bankAccount->getMandateStatus() == BankAccount::MANDATE_CANCELLED) ?
                    $bankAccount->getMandateCancelledExplanation() : '',
                count($policy->getSuccessfulUserPaymentCredits()) > 0 ? 'yes' : 'no',
                ($lastReverted && !$reschedule) ? 'yes' : 'no',
                $policy->getPremium()->getYearlyPremiumPrice(),
                $policy->getPremiumPaid(),
                $policy->getUnderwritingOutstandingPremium(),
                $policy->getBadDebtAmount()
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
        $users = $repo->findAll();
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
            if (!$user->getBillingAddress()) {
                continue;
            }
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
        $lines[] = implode(',', [
            "Date",
            "Payment ID",
            "Transaction ID",
            "Result",
            "Policy Number",
            "Message",
            "Details"
        ]);

        /** @var CheckoutPayment $transaction */
        foreach ($transactions as $transaction) {
            /** @var Policy $policy */
            $policy = $transaction->getPolicy();

            $lines[] = implode(',', [
                $transaction->getDate()->format('jS M Y H:i'),
                $transaction->getId(),
                $transaction->getReceipt(),
                $transaction->getResult(),
                $policy->getPolicyNumber(),
                $transaction->getMessage(),
                $transaction->getDetails()
            ]);
        }
        if (!$skipS3) {
            $fileName = $now->format('Y') . '/' . $now->format('m') . '/' . 'checkOutTransactions.csv';
            $this->uploadS3(implode(PHP_EOL, $lines), $fileName);
        }

        return $lines;
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
