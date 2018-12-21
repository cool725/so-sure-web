<?php
namespace AppBundle\Service;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Repository\ChargeRepository;
use AppBundle\Repository\CashbackRepository;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Claim;
use AppBundle\Document\Cashback;
use AppBundle\Document\Lead;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Document\Payment\SoSurePotRewardPayment;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\Payment\PolicyDiscountRefundPayment;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\Payment\DebtCollectionPayment;
use DateInterval;
use DateTime;
use DateTimeZone;

class AffiliateService
{
    use DateTrait;
    protected $dm;
    protected $logger;
    protected $policyService;
    protected $chargeRepository;
    protected $affiliateRepository;

    /**
     * Builds the affiliate service and sends in it's dependencies as arguments.
     * @param DocumentManager $dm            is the document manager.
     * @param LoggerInterface $logger        is the logger.
     * @param PolicyService   $policyService is the policy service.
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->policyService = $policyService;
        /** @var ChargeRepository $chargeRepository */
        $this->chargeRepository = $dm->getRepository(Charge::class);
        /** @var affiliateRepository $affiliateRepository */
        $this->affiliateRepository = $dm->getRepository(AffiliateCompany::class);
    }

    /**
     * Generates all new charges needed for all affiliate companies.
     * @param array     $affiliates is the list of affiliates to generate for or null to get all affiliates.
     * @param \DateTime $date       is the time and date to be considered as current.
     * @return array the list of all the charges that were just generated.
     */
    public function generate($affiliates = null, \DateTime $date = null)
    {
        if ($affiliates === null) {
            $affiliates = $this->affiliateRepository->findAll();
        }
        if (!$date) {
            $date = new \DateTime();
        }
        $generatedCharges = [];
        foreach ($affiliates as $affiliate) {
            $model = $affiliate->getChargeModel();
            if ($model == AffiliateCompany::MODEL_ONE_OFF) {
                $this->oneOffCharges($affiliate, $date, $generatedCharges);
            } elseif ($model == AffiliateCompany::MODEL_ONGOING) {
                $this->ongoingCharges($affiliate, $date, $generatedCharges);
            } else {
                $this->logger->error(
                    "Trying to charge for affiliate ".($affiliate->getName())." which lacks charge model."
                );
            }
        }
        $this->dm->flush();
        return $generatedCharges;
    }

    /**
     * Performs one off charges logic for a given affiliate.
     * @param AffiliateCompany $affiliate        is the affiliate we are performing one off charges for.
     * @param \DateTime        $date             is the time and date to be considered as current.
     * @param array            $generatedCharges is an optional reference to an array in which generated charges can go.
     * @return array the generated charges array.
     */
    public function oneOffCharges(AffiliateCompany $affiliate, \DateTime $date, & $generatedCharges = [])
    {
        $users = $this->getMatchingUsers($affiliate, $date);
        foreach ($users as $user) {
            if (!$this->chargeRepository->findLastByUser($user, Charge::TYPE_AFFILIATE)) {
                $generatedCharges[] = $this->createCharge($affiliate, $user, $user->getFirstPolicy(), $date);
            }
        }
        return $generatedCharges;
    }

    /**
     * Performs ongoing charges logic for an affiliate.
     * @param AffiliateCompany $affiliate        is the affiliate company that is performing ongoing charges.
     * @param \DateTime        $date             is the time and date to be considered as current.
     * @param array            $generatedCharges is an optional reference to an array in which generated charges can go.
     * @return array|null the generated charges array, or null if the current state is ambiguous and a notice has been
     *                    logged.
     */
    public function ongoingCharges(AffiliateCompany $affiliate, \DateTime $date, & $generatedCharges = [])
    {
        $renewalDays = static::intervalDays(($affiliate->getRenewalDays() ?: 0) - ($affiliate->getDays() ?: 0));
        $renewalWait = clone $date;
        $renewalWait->sub(new DateInterval("P1Y"))->sub($renewalDays);
        $users = $this->getMatchingUsers($affiliate, $date);
        foreach ($users as $user) {
            $policies = $user->getValidPolicies(true);
            $charge = $this->chargeRepository->findLastByUser($user, Charge::TYPE_AFFILIATE);
            if (count($policies) == 1) {
                if (($charge && $charge->getCreatedDate() < $renewalWait) || !$charge) {
                    $charge = $this->createCharge($affiliate, $user, $policies[0], $date);
                    $generatedCharges[] = $charge;
                }
            } elseif ($charge) {
                foreach ($policies as $policy) {
                    $previous = $policy->getPreviousPolicy();
                    if ($previous && !$policy->hasNextPolicy()) {
                        if ($previous->getAffiliate()) {
                            if ($policy->isPolicyOldEnough($affiliate->getRenewalDays(), $date) &&
                                !$policy->getAffiliate()) {
                                $charge = $this->createCharge($affiliate, $user, $policy, $date);
                                $generatedCharges[] = $charge;
                            }
                        } else {
                            $this->logger->error(
                                "User ".$user->getEmail()." has previous affiliate charges but renewal policy ".
                                $policy->getId()." cannot find charges attributed to it's predecessor ".
                                $previous->getId()."."
                            );
                            return null;
                        }
                    }
                }
            } else {
                $this->logger->error(
                    "User ".$user->getEmail()." has multiple active policies, but no affiliate charges recorded."
                );
                return null;
            }
        }
        return $generatedCharges;
    }

    /**
     * Get all users that correspond to a given affiliate's campaign source or lead source fields, and within a given
     * set of aquisition statuses.
     * @param AffiliateCompany $affiliate     is the affiliate company to find users for.
     * @param \DateTime        $date          is the time and date to be considered as current.
     * @param array            $status        is the set of aquisition statuses within which all users must fall.
     * @param boolean          $ignoreCharged if this is true we don't return users who have affiliate charges.
     * @return array containing the users.
     */
    public function getMatchingUsers(
        AffiliateCompany $affiliate,
        \DateTime $date = null,
        $status = [User::AQUISITION_PENDING],
        $ignoreCharged = false
    ) {
        $campaignUsers = [];
        $leadUsers = [];
        $userRepo = $this->dm->getRepository(User::class);
        if (!$date) {
            $date = new \DateTime();
        }
        if (mb_strlen($affiliate->getCampaignSource()) > 0) {
            $campaignUsers = $userRepo->findBy([
                'attribution.campaignSource' => $affiliate->getCampaignSource()
            ]);
        }
        if (mb_strlen($affiliate->getLeadSource()) > 0 && mb_strlen($affiliate->getLeadSourceDetails()) > 0) {
            $leadUsers = $userRepo->findBy([
                'leadSource' => $affiliate->getLeadSource(),
                'leadSourceDetails' => $affiliate->getLeadSourceDetails()
            ]);
        }
        $users = [];
        foreach ($campaignUsers as $user) {
            if ($ignoreCharged && $this->chargeRepository->findLastByUser($user, Charge::TYPE_AFFILIATE)) {
                continue;
            }
            if (in_array($user->aquisitionStatus($affiliate->getDays(), $date), $status)) {
                $users[] = $user;
            }
        }
        foreach ($leadUsers as $user) {
            if ($ignoreCharged && $this->chargeRepository->findLastByUser($user, Charge::TYPE_AFFILIATE)) {
                continue;
            }
            if (in_array($user->aquisitionStatus($affiliate->getDays(), $date), $status)) {
                $users[] = $user;
            }
        }
        return $users;
    }

    /**
     * Tells you how many days until affiliate charge can be paid for a given user.
     * @param AffiliateCompany $affiliate is the affiliate company so we can get their charge model and day periods.
     * @param User             $user      is the user for whom we are checking.
     * @return int the number of days.
     */
    public function daysToAquisition($affiliate, $user, $now = null)
    {
        if (!$now) {
            $now = new \DateTime();
        }
        $model = $affiliate->getChargeModel();
        $charge = $this->chargeRepository->findLastByUser($user, Charge::TYPE_AFFILIATE);
        $policy = $user->getFirstPolicy();
        if ($model == AffiliateCompany::MODEL_ONE_OFF || !$charge) {
            $start = clone $policy->getStart();
            $start->add(static::intervalDays($affiliate->getDays()));
            return static::daysFrom($start, $now);
        } elseif ($model == AffiliateCompany::MODEL_ONGOING) {
            $dayDifference = ($affiliate->getRenewalDays() ?: 0) - ($affiliate->getDays() ?: 0);
            $chargeDate = clone $charge->getCreatedDate();
            $chargeDate->add(new \DateInterval("P1Y"));
            return static::daysFrom($chargeDate, $now) + $dayDifference;
        }
        return 0;
    }

    /**
     * Creates an affiliate charge, associates it with the given user, and confirms the given policy with the
     * affiliate company, then persists it all in the database. The cost of the charge is set as the affiliate's CPA
     * property.
     * @param AffiliateCompany $affiliate is the affiliate company who the charge is being made for.
     * @param User             $user      is the user that the charge is made regarding.
     * @param Policy           $policy    is the policy that the charge is made regarding.
     * @param \DateTime        $date      is the time and date to be considered as current.
     * @return Charge the charge that has been created.
     */
    private function createCharge(AffiliateCompany $affiliate, User $user, Policy $policy, \DateTime $date)
    {
        $charge = new Charge();
        $charge->setAmount($affiliate->getCPA());
        $charge->setType(Charge::TYPE_AFFILIATE);
        $charge->setCreatedDate(clone $date);
        $charge->setUser($user);
        $charge->setAffiliate($affiliate);
        $charge->setPolicy($policy);
        if ($policy->getAffiliate() === null) {
            $affiliate->addConfirmedPolicies($policy);
        }
        $promotion = $affiliate->getPromotion();
        if ($promotion) {
            try {
                $this->policyService->enterPromotion($policy, $promotion, $date);
            } catch (PromotionInactiveException $e) {
                // TODO: in future add front end ability to make promotions active/inactive and when they are made
                //       inactive automatically remove from all affiliates.
                $this->logger->error("Affiliate ".$affiliate->getName()." is still trying to use inactive promotion.");
            }
        }
        $this->dm->persist($charge);
        return $charge;
    }
}
