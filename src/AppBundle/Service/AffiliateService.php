<?php
namespace AppBundle\Service;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\Charge;
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
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
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

    public function generate()
    {
        $generatedCharges = [];
        $repo = $this->dm->getRepository(AffiliateCompany::class);
        $affiliates = $repo->findAll();

        foreach ($affiliates as $affiliate) {
            /** @var AffiliateCompany $affiliate */
            $users = $this->getMatchingUsers($affiliate);
            foreach ($users as $user) {
                /** @var User $user */
                if ($user->isAffiliateCandidate($affiliate->getDays())) {
                    $charge = new Charge();
                    $charge->setType(Charge::TYPE_AFFILIATE);
                    $charge->setAmount($affiliate->getCPA());
                    $charge->setUser($user);
                    $this->dm->persist($charge);
                    $affiliate->addConfirmedUsers($user);
                    $generatedCharges[] = $charge;
                }
            }
        }
        $this->dm->flush();
        return $generatedCharges;
    }

    public function getMatchingUsers(AffiliateCompany $affiliate, $confirmed = false)
    {
        $campaignUsers = [];
        $leadUsers = [];
        $userRepo = $this->dm->getRepository(User::class);
        $matchOperator = $confirmed ? '$ne' : '$eq';

        if (mb_strlen($affiliate->getCampaignSource()) > 0) {
            $campaignUsers = $userRepo->findBy([
                'attribution.campaignSource' => $affiliate->getCampaignSource(),
                'affiliate' => [$matchOperator => null],
            ]);
        }

        if (mb_strlen($affiliate->getLeadSource()) > 0 && mb_strlen($affiliate->getLeadSourceDetails()) > 0) {
            $leadUsers = $userRepo->findBy([
                'leadSource' => $affiliate->getLeadSource(),
                'leadSourceDetails' => $affiliate->getLeadSourceDetails(),
                'affiliate' => [$matchOperator => null],
            ]);
        }

        return array_merge($campaignUsers, $leadUsers);
    }
}
