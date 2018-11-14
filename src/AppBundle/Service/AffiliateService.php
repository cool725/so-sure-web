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
     * @return array the list of all the charges that were just generated.
     */
    public function generate()
    {
        $generatedCharges = [];
        $affiliates = $this->dm->getRepository(AffiliateCompany::class)->findAll();

        foreach ($affiliates as $affiliate) {
            $model = $affiliate->getChargeModel();
            if ($model == AffiliateCompany::MODEL_ONE_OFF) {
                $this->generateOneOffCharges($affiliate, $generatedCharges);
            } elseif ($model == AffiliateCompany::MODEL_ONGOING) {
                $this->generateOngoingCharges($affiliate, $generatedCharges);
            }
        }
        return $generatedCharges;
    }

    /**
     * Confirms and charges for all unconfirmed policies belonging to users belonging to the given affiliate, and
     * confirms unconfirmed users at the same time.
     * @param AffiliateCompany $affiliate is the affiliate company to run for.
     * @param array $charges is a reference to an array into which the charges added for reference.
     * @return array of the charges made.
     */
    public function generateOngoingCharges($affiliate, & $charges = null)
    {
        $policyRepo = $this->dm->getRepository(Policy::class);
        $users = $this->getMatchingUsers($affiliate, [User::AQUISITION_PENDING, User::AQUISITION_CONFIRMED]);
        foreach ($users as $user) {
            if (!$user->getAffiliate()) {
                $affiliate->addConfirmedUsers($user);
            }
            for ($policy = $user->getFirstPolicy; $policy; $policy = $policy->getNextPolicy()) {
                if ($policy->aquisitionStatus() != Policy::AQUISITION_POLICY_PENDING) {
                    continue;
                }
                $charge = new Charge();
                $charge->setType(Charge::TYPE_AFFILIATE);
                $charge->setAmount($affiliate->getCPA());
                $charge->setUser($user);
                $charge->setAffiliate($affiliate);
                $this->dm->persist($charge);
                $affiliate->addConfirmedPolicies($policy);
                if (isset($charges)) {
                    $charges[] = $charge;
                }
            }
        }
        $this->dm->flush();
    }

    /**
     * Confirms and charges for all users that belong to the given affiliate but are not yet confirmed.
     * @param AffiliateCompany $affiliate is the affiliate company to run for.
     * @return array of all the charges made.
     */
    public function generateOneOffCharges($affiliate, & $charges = null)
    {
        $users = $this->getMatchingUsers($affiliate);
        foreach ($users as $user) {
            $charge = new Charge();
            $charge->setType(Charge::TYPE_AFFILIATE);
            $charge->setAmount($affiliate->getCPA());
            $charge->setUser($user);
            $charge->setAffiliate($affiliate);
            $this->dm->persist($charge);
            $affiliate->addConfirmedUsers($user);
            if (isset($charges)) {
                $charges[] = $charge;
            }
        }
        $this->dm->flush();
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
            if (in_array($user->aquisitionStatus(), $status)) {
                $users[] = $user;
            }
        }
        foreach ($leadUsers as $user) {
            if (in_array($user->aquisitionStatus(), $status)) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
