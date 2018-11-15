<?php
namespace AppBundle\Service;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Document\Payment\BacsIndemnityPayment;
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
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    /**
     * Generates all new charges needed for all affiliate companies.
     * @param array $affiliates is the list of affiliates to generate for. Generally you will want all but it is easier
     *                          to test when the method does not operate indiscriminately.
     * @return array the list of all the charges that were just generated.
     */
    public function generate($affiliates)
    {
        $generatedCharges = [];
        foreach ($affiliates as $affiliate) {
            $model = $affiliate->getChargeModel();
            if ($model == AffiliateCompany::MODEL_ONE_OFF) {
                $users = $this->getMatchingUsers($affiliate);
                foreach ($users as $user) {
                    if (!$user->getLastCharge(Charge::CHARGE_AFFILIATE)) {
                        $generatedCharges[] = $this->createCharge($affiliate, $user, $user->getValidPolicies()[0]);
                    }
                }
            } elseif ($model == AffiliateCompany::MODEL_ONGOING) {
                $users = $this->getMatchingUsers($affiliate);
                foreach ($users as $user) {
                    $policies = $user->getValidPolicies(true);
                    if (count($policies) == 1) {
                        // The user has only one active policy.
                        if (
                            ($user->getLastCharge(Charge::CHARGE_AFFILIATE) && chargeIsOldEnough) ||
                            userOldEnoughForFirstCharge
                        ) {
                            // When the user has already made an affiliate charge before, but not recently.
                            $generatedCharges[] = $this->createCharge($affiliate, $user, $policies[0]);
                        }
                    } else {
                        // User has multiple active policies.
                        $charge = $user->getLastCharge(Charge::CHARGE_AFFILIATE);

                        if ($charge) {
                            // Multiple active policies and there exist charges.
                            foreach ($policies as $policy) {
                                if ($policy->getStatus() == Policy::STATUS_RENEWAL) {
                                    $previous = $policy->getPreviousPolicy();
                                    if ($previous->getAffiliate()) {
                                        // Previous policy has a charge.
                                        if ($previous->oldEnough()) {
                                            $generatedCharges[] = $this->createCharge($affiliate, $user, $policy);
                                        }
                                    } else {
                                        warn(
                                            "User ".$user->getEmail()." has previous affiliate charges but renewal ".
                                            "policy ".$policy->getCode()." cannot find charges attributed to it's ".
                                            "predecessor ".$previous->getCode()."."
                                        );
                                    }
                                }
                            }
                        } else {
                            warn(
                                "User ".$user->getEmail()." has multiple active policies, but no affiliate charges ".
                                "recorded."
                            );
                        }
                    }
                }
            }
        }
        return $generatedCharges;
    }

    /**
     * Get all users that correspond to a given affiliate's campaign source or lead source fields, and within a given
     * set of aquisition statuses.
     * @param AffiliateCompany $affiliate is the affiliate company to find users for.
     * @param array            $status    is the set of aquisition statuses within which all users must fall.
     * @return array containing the users.
     */
    public function getMatchingUsers(AffiliateCompany $affiliate, $status = [User::AQUISITION_PENDING])
    {
        $campaignUsers = [];
        $leadUsers = [];
        $userRepo = $this->dm->getRepository(User::class);
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
            if (in_array($user->aquisitionStatus($affiliate->getDays()), $status)) {
                $users[] = $user;
            }
        }
        foreach ($leadUsers as $user) {
            if (in_array($user->aquisitionStatus($affiliate->getDays()), $status)) {
                $users[] = $user;
            }
        }
        return $users;
    }

    /**
     * Creates an affiliate charge, associates it with the given user, and confirms the given policy with the
     * affiliate company, then persists it all in the database. The cost of the charge is set as the affiliate's CPA
     * property.
     * @param AffiliateCompany $affiliate is the affiliate company who the charge is being made for.
     * @param User             $user      is the user that the charge is made regarding.
     * @param Policy           $policy    is the policy that the charge is made regarding.
     * @return Charge the charge that has been created.
     */
    public function createCharge($affiliate, $user, $policy) {
        $charge = new Charge();
        $charge->setAmount($affiliate->getCPA());
        $charge->setType(Charge::TYPE_AFFILIATE);
        $charge->setCreatedDate(new \DateTime());
        $charge->setUser($user);
        $charge->setAffiliate($affiliate);
        $charge->setPolicy($policy);
        $affiliate->addConfirmedPolicies($policy);
        return $charge;
    }
}
