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
use AppBundle\Helpers\CsvHelper;
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
     * @var boolean
     */
    protected $reduced;

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
     * @param DocumentManager $dm      for the report to use.
     * @param DateTimeZone    $tz      is the time zone to report in.
     * @param boolean         $reduced is whether to remove some columns to save time.
     */
    public function __construct(DocumentManager $dm, DateTimeZone $tz, $reduced = false)
    {
        parent::__construct($dm, $tz);
        $this->reduced = $reduced;
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
        return $this->reduced ? 'policies-reduced.csv' : 'policies.csv';
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        return CsvHelper::ignoreBlank(
            'Policy Number',
            'Policy Holder Id',
            'Age of Policy Holder',
            'Postcode of Policy Holder',
            'Gender',
            'Make',
            $this->reduced ? 'Make/Model' : null,
            'Make/Model/Memory',
            'Policy Start Date',
            'Policy End Date',
            'Premium Installments',
            $this->reduced ? 'First Time Policy' : null,
            $this->reduced ? 'Policy Number Prior Renewal' : null,
            $this->reduced ? 'Policy Number Renewal' : null,
            'Policy Result of Upgrade',
            'This Policy is the X renewal',
            'Policy Status',
            $this->reduced ? 'Expected Unpaid Cancellation Date' : null,
            'Policy Cancellation Reason',
            $this->reduced ? 'Requested Cancellation (Phone Damaged Prior to Policy)' : null,
            $this->reduced ? 'Requested Cancellation Reason (Phone Damaged Prior to Policy)' : null,
            'Invitations',
            'Connections',
            'Reward Pot',
            'Pic-Sure Status',
            'Total Number of Claims',
            $this->reduced ? 'Number of Approved/Settled Claims' : null,
            $this->reduced ? 'Number of Withdrawn/Declined Claims' : null,
            'Policy Purchase Time',
            'Lead Source',
            'First Scode Type',
            'First Scode Name',
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
            $this->reduced ? 'Bacs Mandate Status' : null,
            $this->reduced ? 'Bacs Mandate Cancelled Reason' : null,
            $this->reduced ? 'Successful Payment' : null,
            $this->reduced ? 'Latest Payment Failed Without Reschedule' : null,
            'Yearly Premium',
            $this->reduced ? 'Premium Paid' : null,
            $this->reduced ? 'Premium Outstanding' : null,
            $this->reduced ? 'Past Due Amount (Bad Debt Only)' : null,
            $this->reduced ? 'Referrals made' : null,
            $this->reduced ? 'Referrals made amount' : null,
            $this->reduced ? 'Referrals received' : null,
            $this->reduced ? 'Referrals received amount' : null,
            'Company of Policy'
        );
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
        $scodeType = $this->getFirstSCodeUsedType($connections);
        $scodeName = $this->getFirstSCodeUsedCode($connections);
        $this->add(...CsvHelper::ignoreBlank(
            $policy->getPolicyNumber(),
            $user->getId(),
            $user->getAge(),
            $user->getBillingAddress()->getPostcode(),
            $user->getGender() ?: '',
            $phone->getMake(),
            $this->reduced ? null : sprintf('%s %s', $phone->getMake(), $phone->getModel()),
            $phone,
            DateTrait::timezoneFormat($policy->getStart(), $this->tz, 'Y-m-d'),
            DateTrait::timezoneFormat($policy->getEnd(), $this->tz, 'Y-m-d'),
            $policy->getPremiumInstallments(),
            $this->reduced ? null : ($policy->useForAttribution() ? 'yes' : 'no'),
            $this->reduced ? null : ($previous ? $previous->getPolicyNumber() : ''),
            $this->reduced ? null : ($next ? $next->getPolicyNumber() : ''),
            $this->getPreviousPolicyIsUpgrade($policy),
            $policy->getGeneration(),
            $policy->getStatus(),
            $this->reduced ? null : (
                $policy->getStatus() == Policy::STATUS_UNPAID ? 
                    DateTrait::timezoneFormat($policy->getPolicyExpirationDate(), $this->tz, 'Y-m-d') : ''
            ),
            $policy->getStatus() == Policy::STATUS_CANCELLED ? $policy->getCancelledReason() : '',
            $this->reduced ? null : ($policy->hasRequestedCancellation() ? 'yes' : 'no'),
            $this->reduced ? null : ($policy->getRequestedCancellationReason() ?: ''),
            count($policy->getInvitations()),
            count($policy->getStandardConnections()),
            $policy->getPotValue(),
            $policy->getPicSureStatus() ?: 'unstarted',
            count($policy->getClaims()),
            $this->reduced ? null : count($policy->getApprovedClaims()),
            $this->reduced ? null : count($policy->getWithdrawnDeclinedClaims()),
            DateTrait::timezoneFormat($policy->getStart(), $this->tz, 'H:i'),
            $policy->getLeadSource() ?: '',
            $scodeType ?: '',
            $scodeName ?: '',
            $this->getPromoCodesUsed($this->rewardRepo, $connections) ?: '',
            $this->policyHasSignUpBonus($this->rewardRepo, $connections) ? 'yes' : 'no',
            $latestAttribution ? $latestAttribution->getCampaignSource() : '',
            $latestAttribution ? $latestAttribution->getCampaignName() : '',
            $latestAttribution ? $latestAttribution->getReferer() : '',
            $attribution ? $attribution->getCampaignSource() : '',
            $attribution ? $attribution->getCampaignName() : '',
            $attribution ? $attribution->getReferer() : '',
            $policy->getPurchaseSdk() ?: '',
            $policy->getUsedPaymentType() ?: '',
            $this->reduced ? null :(($bankAccount && $policy->isActive()) ? $bankAccount->getMandateStatus() : ''),
            $this->reduced ? null :(($bankAccount && $policy->isActive() &&
                $bankAccount->getMandateStatus() == BankAccount::MANDATE_CANCELLED) ?
                    $bankAccount->getMandateCancelledExplanation() : ''),
            $this->reduced ? null : (count($policy->getSuccessfulUserPaymentCredits()) > 0 ? 'yes' : 'no'),
            $this->reduced ? null : (($lastReverted && !$reschedule) ? 'yes' : 'no'),
            $policy->getPremium()->getYearlyPremiumPrice(),
            $this->reduced ? null : $policy->getPremiumPaid(),
            $this->reduced ? null : $policy->getUnderwritingOutstandingPremium(),
            $this->reduced ? null : $policy->getBadDebtAmount(),
            $this->reduced ? null : count($policy->getInviterReferralBonuses()),
            $this->reduced ? null : $policy->getPaidInviterReferralBonusAmount(),
            $this->reduced ? null : count($policy->getInviteeReferralBonuses()),
            $this->reduced ? null : $policy->getPaidInviteeReferralBonusAmount(),
            $company ? $company->getName() : ''
        ));
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
