<?php

namespace AppBundle\Classes;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Reward;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SCode;
use AppBundle\Repository\RewardRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use DateTime;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use stdClass;

/**
 * generates the normal policies bi report.
 */
class PolicyBiReport extends PolicyReport
{
    /**
     * @var RewardRepository $rewardRepo
     */
    protected $rewardRepo;

    /**
     * @var ScheduledPaymentRepository $scheduledPaymentRepo
     */
    protected $scheduledPaymentRepo;

    /**
     * Creates the policy picsure report.
     * @param DocumentManager $dm            for the report to use.
     * @param DateTimeZone    $tz            is the time zone to report in.
     */
    public function __construct(DocumentManager $dm, DateTimeZone $tz)
    {
        parent::__construct($dm, $tz);
        /** @var RewardRepository $rewardRepo */
        $rewardRepo = $this->dm->getRepository(Reward::class);
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $this->rewardRepo = $rewardRepo;
        $this->scheduledPaymentRepo = $scheduledPaymentRepo;
    }

    /**
     * @inheritDoc
     */
    public function getFile()
    {
        return 'policies.csv';
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        return [
            'Policy Number',
            'Policy Holder Id',
            'Age of Policy Holder',
            'Postcode of Policy Holder',
            'Gender',
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
            'Company of Policy',
            'Referrals made',
            'Referrals made amount',
            'Referrals received',
            'Referrals received amount'
        ];
    }

    /**
     * @inheritDoc
     */
    public function process(Policy $policy)
    {
        if (!($policy instanceof PhonePolicy) || $policy->getEnd() <= $policy->getStart()) {
            return;
        }
        $connections = $policy->getConnections();
        $user = $policy->getUser();
        $previous = $policy->getPreviousPolicy();
        $next = $policy->getNextPolicy();
        $phone = $policy->getPhone();
        $billing = $user->getBillingAddress();
        $attribution = $user->getAttribution();
        $latestAttribution = $user->getLatestAttribution();
        $bankAccount = $policy->getPolicyOrUserBacsBankAccount();
        $reschedule = null;
        $lastReverted = $policy->getLastRevertedScheduledPayment();
        if ($lastReverted) {
            $reschedule = $this->scheduledPaymentRepo->getRescheduledBy($lastReverted);
        }
        $company = $policy->getCompany();
        $this->add(
            $policy->getPolicyNumber(),
            $user->getId(),
            $user->getAge(),
            $user->getBillingAddress()->getPostcode(),
            $user->getGender() ?: '',
            $phone->getMake(),
            sprintf('%s %s', $phone->getMake(), $phone->getModel()),
            $phone,
            DateTrait::timezoneFormat($policy->getStart(), $this->tz, 'Y-m-d'),
            DateTrait::timezoneFormat($policy->getEnd(), $this->tz, 'Y-m-d'),
            $policy->getPremiumInstallments(),
            $policy->useForAttribution() ? 'yes' : 'no',
            $previous ? $previous->getPolicyNumber() : '',
            $next ? $next->getPolicyNumber() : '',
            $this->getPreviousPolicyIsUpgrade($policy),
            $policy->getGeneration(),
            $policy->getStatus(),
            $policy->getStatus() == Policy::STATUS_UNPAID ?
                DateTrait::timezoneFormat($policy->getPolicyExpirationDate(), $this->tz, 'Y-m-d') : '',
            $policy->getStatus() == Policy::STATUS_CANCELLED ? $policy->getCancelledReason() : '',
            $policy->hasRequestedCancellation() ? 'yes' : 'no',
            $policy->getRequestedCancellationReason() ?: '',
            count($policy->getInvitations()),
            count($policy->getStandardConnections()),
            $policy->getPotValue(),
            $policy->getPicSureStatus() ?: 'unstarted',
            count($policy->getClaims()),
            count($policy->getApprovedClaims()),
            count($policy->getWithdrawnDeclinedClaims()),
            DateTrait::timezoneFormat($policy->getStart(), $this->tz, 'H:i'),
            $policy->getLeadSource(),
            $this->policyHasSignUpBonus($this->rewardRepo, $connections) ? 'yes' : 'no',
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
            $company ? $company->getName() : '',
            count($policy->getInviterReferralBonuses()),
            $policy->getPaidInviterReferralBonusAmount(),
            count($policy->getInviteeReferralBonuses()),
            $policy->getPaidInviteeReferralBonusAmount()
        );
    }

    /**
     * Gets the first used scode for a policy.
     * @param array $connections contains the connections to inspect.
     * @return string the type of the first scode used in the given set of connections.
     */
    private function getFirstSCodeUsedType($connections)
    {
        $oldest = new DateTime();
        $firstConnection = new stdClass();
        /** @var Connection $connection */
        foreach ($connections as $connection) {
            $signUp = false;
            if ($connection instanceof RewardConnection) {
                $signUp = $this->rewardRepo->isSignUpBonusSCode($connection);
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

    /**
     * Gives you the first scode used for a policy.
     * @param array $connections list of connections to look for first scode in.
     * @return string the first scode as a string.
     */
    private function getFirstSCodeUsedCode($connections)
    {
        $oldest = new DateTime();
        $firstConnection = new stdClass();
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

    /**
     * Gives you the list of scodes connected with by a given policy.
     * @param array $connections list of the connections within which to find scodes.
     * @return string list of the scodes used.
     */
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

    /**
     * Gives you a string of all the promo codes used within the given set of connections.
     * @param RewardRepository $rewardRepo  is used to find rewards.
     * @param array            $connections is the list of connections to look in.
     * @return string the list of all the promo codes.
     */
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

    /**
     * Tells you if the given policy has a sign up bonus.
     * @param RewardRepository $rewardRepo  is used to find rewards.
     * @param array            $connections is the list of connections to check in.
     * @return bool true iff it has the sign up bonus.
     */
    private function policyHasSignUpBonus(RewardRepository $rewardRepo, $connections)
    {
        foreach ($connections as $connection) {
            if ($connection instanceof RewardConnection && $rewardRepo->isSignUpBonusSCode($connection)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tells you if the previous policy of the given policy is an upgrade.
     * @param Policy $policy is the policy to check.
     * @return string textual yes or no answer as to whether the previous policy is an upgrade.
     */
    private function getPreviousPolicyIsUpgrade(Policy $policy)
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
}
