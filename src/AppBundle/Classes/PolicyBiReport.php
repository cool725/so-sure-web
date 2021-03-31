<?php

namespace AppBundle\Classes;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Reward;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Helpers\CsvHelper;
use AppBundle\Repository\RewardRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use CensusBundle\Document\Census;
use CensusBundle\Service\SearchService;
use DateTime;
use DateTimeZone;
use Doctrine\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\DocumentManager;
use http\Exception\RuntimeException;
use Psr\Log\LoggerInterface;
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

    /** @var LoggerInterface */
    protected $logger;

    /** @var int */
    protected $headerItems;

    /**
     * Creates the policy picsure report.
     * @param SearchService   $searchService provides geographical information about users.
     * @param DocumentManager $dm            for the report to use.
     * @param DateTimeZone    $tz            is the time zone to report in.
     * @param LoggerInterface $logger        is used for logging.
     * @param boolean         $reduced       is whether to remove some columns to save time.
     */
    public function __construct(
        SearchService $searchService,
        DocumentManager $dm,
        DateTimeZone $tz,
        LoggerInterface $logger,
        $reduced = false
    ) {
        $this->reduced = $reduced;
        parent::__construct($searchService, $dm, $tz, $logger, $reduced);
        /** @var RewardRepository $rewardRepo */
        $rewardRepo = $this->dm->getRepository(Reward::class);
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $this->rewardRepo = $rewardRepo;
        $this->scheduledPaymentRepo = $scheduledPaymentRepo;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function getFile()
    {
        return $this->reduced ? 'policies-reduced' : 'policies';
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        $full = true;
        if ($this->reduced) {
            $full = false;
        }

        $hItems = CsvHelper::ignoreBlank(
            'Policy Number',
            'Policy Holder Id',
            'Age of Policy Holder',
            'Postcode of Policy Holder',
            ($full) ? 'Pen Portrait' : null,
            'Gender',
            ($full) ? 'Total Weekly Income' : null,
            'Make',
            ($full) ? 'Make/Model' : null,
            'Make/Model/Memory',
            'Policy Start Date',
            'Policy End Date',
            'Premium Installments',
            ($full) ? 'First Time Policy' : null,
            ($full) ? 'Policy Number Prior Renewal' : null,
            ($full) ? 'Policy Number Renewal' : null,
            'Policy Result of Upgrade',
            'This Policy is the X renewal',
            'Policy Status',
            ($full) ? 'Expected Unpaid Cancellation Date' : null,
            'Policy Cancellation Reason',
            ($full) ? 'Requested Cancellation (Phone Damaged Prior to Policy)' : null,
            ($full) ? 'Requested Cancellation Reason (Phone Damaged Prior to Policy)' : null,
            'Invitations',
            'Connections',
            'Reward Pot',
            'Pic-Sure Status',
            'Total Number of Claims',
            ($full) ? 'Number of Approved/Settled Claims' : null,
            ($full) ? 'Number of Withdrawn/Declined Claims' : null,
            'Policy Purchase Time',
            'Lead Source',
            'First Scode Type',
            'First Scode Name',
            ($full) ? 'All SCodes Used' : null,
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
            ($full) ? 'Bacs Mandate Status' : null,
            ($full) ? 'Bacs Mandate Cancelled Reason' : null,
            ($full) ? 'Successful Payment' : null,
            ($full) ? 'Latest Payment Failed Without Reschedule' : null,
            'Yearly Premium',
            ($full) ? 'Premium Paid' : null,
            ($full) ? 'Premium Outstanding' : null,
            ($full) ? 'Past Due Amount (Bad Debt Only)' : null,
            'Company of Policy',
            ($full) ? 'Referrals made' : null,
            ($full) ? 'Referrals made amount' : null,
            ($full) ? 'Referrals received' : null,
            ($full) ? 'Referrals received amount' : null
        );

        $this->headerItems = count($hItems);
        return $hItems;
    }

    public function processBatch(array $policy)
    {
        // TODO: Implement process() method.
    }

    /**
     * @inheritDoc
     */
    public function process(Policy $policy)
    {
        try {

            /** @var Policy $policy */
            if (!($policy instanceof PhonePolicy) || $policy->getEnd() <= $policy->getStart()) {
                return;
            }
            $user = $policy->getUser();
            if (!$user instanceof User) {
                return;
            }

            /** @var Phone $phone */
            $phone = $policy->getPhone();

            $previousPolicy = $policy->getPreviousPolicy();
            $previous = '';
            if ($previousPolicy) {
                $previous = $previousPolicy->getPolicyNumber() ?: '';
            }
            $nextPolicy = $policy->getNextPolicy();
            $next = '';
            if ($nextPolicy) {
                $next = $nextPolicy->getPolicyNumber() ?: '';
            }

            $company = $policy->getCompany();
            $companyName = '';
            if ($company) {
                $companyName = $company->getName() ?: '';
            }

            $userId = '';
            $userAge = '';
            $userGender = '';
            $postalCode = '';
            $census = null;
            $income = null;

            if ($user->getId()) {
                $userId = $user->getId();
                $userAge = ($user->getAge() ?: '');
                $userGender = ($user->getGender() ?: '');
                if ($user->getBillingAddress()) {
                    $postalCode = ($user->getBillingAddress()->getPostcode() ?: '');
                    $census = $this->searchService->findNearest($postalCode);
                    $income = $this->searchService->findIncome($postalCode);
                }
            }

            $attribution = $user->getAttribution();
            $aCampaignSrc = '';
            $aCampaignName = '';
            $aCampaignReferer = '';
            if ($attribution) {
                $aCampaignSrc = $attribution->getCampaignSource() ?: '';
                $aCampaignName = $attribution->getCampaignName() ?: '';
                $aCampaignReferer = $attribution->getReferer() ?: '';
            }
            $latestAttribution = $user->getLatestAttribution();
            $laCampaignSrc = '';
            $laCampaignName = '';
            $laCampaignReferer = '';
            if ($latestAttribution) {
                $laCampaignSrc = $latestAttribution->getCampaignSource() ?: '';
                $laCampaignName = $latestAttribution->getCampaignName() ?: '';
                $laCampaignReferer = $latestAttribution->getReferer() ?: '';
            }
            $userAtt = $policy->useForAttribution();
            $userAttribution = 'No';
            if ($userAtt) {
                $userAttribution = ($userAtt ? 'Yes' : 'No');
            }

            $bankAccount = $policy->getPolicyOrUserBacsBankAccount() ?: '';
            $reschedule = null;
            $lastReverted = null;
            $lastPaymentFail = 'No';
            if (!$this->reduced) {
                $lastReverted = $policy->getLastRevertedScheduledPayment();
                if ($lastReverted) {
                    $reschedule = $this->scheduledPaymentRepo->getRescheduledBy($lastReverted);
                    if ($lastReverted && !$reschedule) {
                        $lastPaymentFail = 'Yes';
                    } else {
                        $lastPaymentFail = 'No';
                    }
                }
            }
            $connections = $policy->getConnections();
            $scodeType = '';
            $scodeName = '';
            $promoCodeUsed = '';
            $allPromoCodes = '';
            $policySignup = '';
            if ($connections) {
                $scodeType = $this->getFirstSCodeUsedType($connections);
                $scodeName = $this->getFirstSCodeUsedCode($connections);
                $promoCodeUsed = ($this->getPromoCodesUsed($this->rewardRepo, $connections) ?: '');
                $allPromoCodes = ($this->getSCodesUsed($connections) ?: '');
                $policySignup = ($this->policyHasSignUpBonus($this->rewardRepo, $connections) ? 'yes' : 'no');
            }

            $cancelledReason = '';
            if ($policy->getStatus() == Policy::STATUS_CANCELLED) {
                if ($policy->getCancelledReason()) {
                    $cancelledReason = $policy->getCancelledReason();
                }
            }

            $expDate = '';
            if ($policy->getStatus() == Policy::STATUS_UNPAID) {
                if ($policy->getPolicyExpirationDate()) {
                    $expDate = DateTrait::timezoneFormat($policy->getPolicyExpirationDate(), $this->tz, 'Y-m-d');
                }
            }

            $startDate = '';
            $purchaseTime = '';
            if ($policy->getStart()) {
                $startDate = DateTrait::timezoneFormat($policy->getStart(), $this->tz, 'Y-m-d');
                $purchaseTime = DateTrait::timezoneFormat($policy->getStart(), $this->tz, 'H:i');
            }
            $endDate = '';
            if ($policy->getEnd()) {
                $endDate = DateTrait::timezoneFormat($policy->getEnd(), $this->tz, 'Y-m-d');
            }

            $phoneMake = '';
            $phoneMakeModel = '';
            $phoneMakeModelMemory = '';
            if ($phone) {
                $phoneMake = ($phone->getMake() ?: '');
                $phoneModel = ($phone->getModel() ?: '');
                $phoneMakeModelMemory = ($phone->getMakeModelMemory() ?: '');
                $phoneMakeModel = sprintf('%s %s', $phoneMake, $phoneModel);
            }

            $numInstallments = '';
            if ($policy->getPremiumInstallments()) {
                $numInstallments = $policy->getPremiumInstallments();
            }

            $policyXRenewal = '';
            if ($policy->getGeneration()) {
                $policyXRenewal = $policy->getGeneration();
            }
            $policyUpgraded = 'No';
            if ($policy->getPolicyUpgraded()) {
                $policyUpgraded = ($policy->getPolicyUpgraded() ? 'Yes' : 'No');
            }

            $penPortrait = '';
            /** @var Census $census */
            if ($census) {
                $penPortrait = ($census->getSubgrp() ?: '');
            }

            $weeklyIncome = '';
            if ($income) {
                $weeklyIncome = sprintf('%0.0f', $income->getTotal()->getIncome());
            }


            $items = CsvHelper::ignoreBlank(
                $policy->getPolicyNumber() ?: '',
                $userId,
                $userAge,
                $postalCode,
                $this->reduced ? null : $penPortrait,
                $userGender,
                $this->reduced ? null : $weeklyIncome,
                $phoneMake,
                $this->reduced ? null : $phoneMakeModel,
                $phoneMakeModelMemory,
                $startDate,
                $endDate,
                $numInstallments,
                $this->reduced ? null : $userAttribution,
                $this->reduced ? null : $previous,
                $this->reduced ? null : $next,
                $policyUpgraded,
                $policyXRenewal,
                $policy->getStatus() ?: '',
                $this->reduced ? null : $expDate,
                $cancelledReason,
                $this->reduced ? null : ($policy->hasRequestedCancellation() ? 'yes' : 'no'),
                $this->reduced ? null : ($policy->getRequestedCancellationReason() ?: ''),
                count($policy->getInvitations()),
                count($policy->getStandardConnections()),
                $policy->getPotValue() ?: '',
                $policy->getPicSureStatus() ?: 'unstarted',
                count($policy->getClaims()),
                $this->reduced ? null : count($policy->getApprovedClaims()),
                $this->reduced ? null : count($policy->getWithdrawnDeclinedClaims()),
                $purchaseTime,
                $policy->getLeadSource() ?: '',
                $scodeType ?: '',
                $scodeName ?: '',
                $this->reduced ? null : $allPromoCodes,
                $promoCodeUsed,
                $policySignup,
                $laCampaignSrc,
                $laCampaignName,
                $laCampaignReferer,
                $aCampaignSrc,
                $aCampaignName,
                $aCampaignReferer,
                $policy->getPurchaseSdk() ?: '',
                $policy->getUsedPaymentType() ?: '',
                $this->reduced ? null : (($bankAccount && $policy->isActive())
                    ? $bankAccount->getMandateStatus() : ''),
                $this->reduced ? null : (($bankAccount && $policy->isActive() &&
                    $bankAccount->getMandateStatus() == BankAccount::MANDATE_CANCELLED) ?
                    $bankAccount->getMandateCancelledExplanation() : ''),
                $this->reduced ? null : (count($policy->getSuccessfulUserPaymentCredits()) > 0 ? 'yes' : 'no'),
                $this->reduced ? null : $lastPaymentFail,
                ($policy->getPremium()) ? $policy->getPremium()->getYearlyPremiumPrice() : '',
                $this->reduced ? null : $policy->getPremiumPaid(),
                $this->reduced ? null : ($policy->getUnderwritingOutstandingPremium() ?: 0),
                $this->reduced ? null : ($policy->getBadDebtAmount() ?: 0),
                $companyName,
                $this->reduced ? null : count($policy->getInviterReferralBonuses()),
                $this->reduced ? null : $policy->getPaidInviterReferralBonusAmount(),
                $this->reduced ? null : count($policy->getInviteeReferralBonuses()),
                $this->reduced ? null : $policy->getPaidInviteeReferralBonusAmount()
            );


            // if header count does not match columns dont get to add section
            // exception may break batch
            if (count($items) !== $this->headerItems) {
                $this->logger->error('Policy missing data: ' . json_encode($items));
            }
            $this->add(...$items);

            // Clear for each row once its done
        } catch (\RuntimeException $e) {
            // Log any records not matching and continue to next record
            $this->logger->error('Error with processing policy: ' . $e->getTraceAsString());
            throw new \RuntimeException('Error with processing policy:' . $e->getMessage());
        } catch (\Exception $ee) {
            $this->logger->error($ee->getTraceAsString());
            throw new \Exception('Error with policy:' . $ee->getMessage());
        }
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
