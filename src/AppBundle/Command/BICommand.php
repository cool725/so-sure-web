<?php

namespace AppBundle\Command;

use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Phone;
use AppBundle\Repository\ClaimRepository;
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
use AppBundle\Document\DateTrait;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Classes\SoSure;

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

    protected function configure()
    {
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
                'only run 1 export [policies, claims, users, invitations, connections, phones]'
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
        ;
    }

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
    }

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
        ]);
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $lines[] = implode(',', [
                sprintf('"%s"', $phone->getMake()),
                sprintf('"%s"', $phone->getModel()),
                sprintf('"%s"', $phone->getMemory()),
                sprintf('"%0.2f"', $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice()),
                sprintf('"%0.2f"', $phone->getInitialPrice()),
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'phones.csv');
        }

        return $lines;
    }

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
        ]);
        foreach ($claims as $claim) {
            /** @var Claim $claim */
            $policy = $claim->getPolicy();
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
                        $this->timezoneFormat($policy->getEnd(), $timezone, 'Y-m-d H:i:s') :
                        ""
                ),

                sprintf('"%s"', $policy->getCancelledReason() ? $policy->getCancelledReason() : ""),
                sprintf('"%s"', count($policy->getStandardConnections())),
                'N/A',
                sprintf(
                    '"%s"',
                    $policy->getCancelledReason() && $policy->getCancelledReason() == Policy::CANCELLED_UPGRADE ?
                        'yes' :
                        'no'
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
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'claims.csv');
        }

        return $lines;
    }

    private function exportPolicies($prefix, $skipS3, \DateTimeZone $timezone)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findAllStartedPolicies($prefix, new \DateTime(SoSure::POLICY_START))->toArray();
        $lines = [];
        $lines[] = implode(',', [
            '"Policy Number"',
            '"Age of Policy Holder"',
            '"Postcode of Policy Holder"',
            '"Policy Start Date"',
            '"Policy End Date"',
            '"Policy Status"',
            '"Policy Holder Id"',
            '"Policy Cancellation Reason"',
            '"Requested Cancellation (Phone Damaged Prior To Policy)"',
            '"Total Number of Claims"',
            '"Number of Approved/Settled Claims"',
            '"Number of Withdrawn/Declined Claims"',
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
            '"Requested Cancellation Reason (Phone Damaged Prior To Policy)"',
            '"Policy Renewed"',
            '"Latest Referer"',
            '"First Campaign Name"',
            '"First Campaign Source"',
            '"First Referer"',
            '"Make"',
            '"Make/Model"',
            '"Connections"',
            '"Invitations"',
            '"pic-sure Status"',
            '"Lead Source"',
            '"Purchase SDK"',
            '"Make/Model/Memory"',
            '"Reward Pot"',
            '"Premium Paid"',
            '"Yearly Premium"',
            '"Premium Outstanding"',
            '"Policy Purchase Time"',
            '"Past Due Amount (Bad Debt Only)"',
            '"Has previous policy"',
            '"Payment Method"',
            '"Expected Unpaid Cancellation Date"',
            '"Bacs Mandate Status"',
            '"First time policy"',
            '"Successful Payment"',
            '"Bacs Mandate Cancelled Reason"'
        ]);
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $user = $policy->getUser();
            $census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode());
            $income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode());
            $lines[] = implode(',', [
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
                sprintf('"%s"', $this->timezoneFormat($policy->getStart(), $timezone, 'Y-m-d')),
                sprintf('"%s"', $this->timezoneFormat($policy->getEnd(), $timezone, 'Y-m-d')),
                sprintf('"%s"', $policy->getStatus()),
                sprintf('"%s"', $user->getId()),
                sprintf('"%s"', $policy->getCancelledReason() ? $policy->getCancelledReason() : null),
                sprintf('"%s"', $policy->hasRequestedCancellation() ? 'yes' : 'no'),
                sprintf('"%s"', count($policy->getClaims())),
                sprintf('"%s"', count($policy->getApprovedClaims(true))),
                sprintf('"%s"', count($policy->getWithdrawnDeclinedClaims())),
                sprintf('"%s"', $census ? $census->getSubgrp() : ''),
                sprintf('"%s"', $user->getGender() ? $user->getGender() : ''),
                $income ? sprintf('"%0.0f"', $income->getTotal()->getIncome()) : '""',
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignName() : ''),
                sprintf(
                    '"%s"',
                    $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignSource() : ''
                ),
                sprintf(
                    '"%s"',
                    $policy->getRequestedCancellationReason() ? $policy->getRequestedCancellationReason() : null
                ),
                sprintf('"%s"', $policy->isRenewed() ? 'yes' : 'no'),
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getReferer() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignName() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getReferer() : ''),
                sprintf('"%s"', $policy->getPhone()->getMake()),
                sprintf('"%s %s"', $policy->getPhone()->getMake(), $policy->getPhone()->getModel()),
                sprintf('"%d"', count($policy->getStandardConnections())),
                sprintf('"%d"', count($policy->getInvitations())),
                sprintf('"%s"', $policy->getPicSureStatus() ? $policy->getPicSureStatus() : 'unstarted'),
                sprintf('"%s"', $policy->getLeadSource()),
                sprintf('"%s"', $policy->getPurchaseSdk()),
                sprintf('"%s"', $policy->getPhone()->__toString()),
                sprintf('"%0.2f"', $policy->getPotValue()),
                sprintf('"%0.2f"', $policy->getPremiumPaid()),
                sprintf('"%0.2f"', $policy->getPremium()->getYearlyPremiumPrice()),
                sprintf('"%0.2f"', $policy->getUnderwritingOutstandingPremium()),
                sprintf('"%s"', $this->timezoneFormat($policy->getStart(), $timezone, 'H:i')),
                sprintf('"%0.2f"', $policy->getBadDebtAmount()),
                sprintf('"%s"', $policy->hasPreviousPolicy() ? 'yes' : 'no'),
                sprintf('"%s"', $policy->getUsedPaymentType()),
                sprintf(
                    '"%s"',
                    $policy->getStatus() == Policy::STATUS_UNPAID ?
                        $this->timezoneFormat($policy->getPolicyExpirationDate(), $timezone, 'Y-m-d') :
                        null
                ),
                sprintf(
                    '"%s"',
                    ($policy->getPolicyOrUserBacsBankAccount() && $policy->isActive(true)) ?
                        $policy->getPolicyOrUserBacsBankAccount()->getMandateStatus() :
                        null
                ),
                sprintf('"%s"', $policy->useForAttribution($prefix) ? 'yes' : 'no'),
                sprintf('"%s"', count($policy->getSuccessfulUserPaymentCredits()) > 0 ? 'yes' : 'no'),
                sprintf(
                    '"%s"',
                    $policy->getPolicyOrUserBacsBankAccount() ?
                        $policy->getPolicyOrUserBacsBankAccount()->getMandateCancelledReason() :
                        null
                )
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'policies.csv');
        }

        return $lines;
    }

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
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : ''),
            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'users.csv');
        }

        return $lines;
    }

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
                sprintf('"%s"', $this->timezoneFormat($invitation->getAccepted(), $timezone, 'Y-m-d H:i:s')),

            ]);
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'invitations.csv');
        }

        return $lines;
    }

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
            // @codingStandardsIgnoreStart
            $lines[] = implode(',', [
                sprintf('"%s"', $connection->getSourcePolicy() ? $connection->getSourcePolicy()->getPolicyNumber() : ''),
                sprintf('"%s"', $connection->getLinkedPolicy() ? $connection->getLinkedPolicy()->getPolicyNumber() : ''),
                sprintf('"%s"', $this->timezoneFormat($connection->getInitialInvitationDate(), $timezone, 'Y-m-d H:i:s')),
                sprintf('"%s"', $connection->getInvitation() ? $connection->getInvitation()->getChannel() : ''),
                sprintf('"%s"', $this->timezoneFormat($connection->getDate(), $timezone, 'Y-m-d H:i:s')),
            ]);
            // @codingStandardsIgnoreEnd
        }
        if (!$skipS3) {
            $this->uploadS3(implode(PHP_EOL, $lines), 'connections.csv');
        }

        return $lines;
    }

    public function uploadS3($data, $filename)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);

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
}
