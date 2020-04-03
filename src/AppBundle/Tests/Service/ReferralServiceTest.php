<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\ReferralBonus;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\CurrencyTrait;

use AppBundle\Service\PolicyService;
use AppBundle\Service\ReferralService;

use AppBundle\Tests\Create;

/**
 * @group functional-nonet
 * @group fixed
 */
class ReferralServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $referralService;
    protected static $policyService;
    protected static $referralRepo;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
         self::$referralRepo = self::$dm->getRepository(ReferralBonus::class);
         /** @var ReferralService $referralService */
         $referralService = self::$container->get('app.referral');
         self::$referralService = $referralService;
         /** @var PolicyService $policyService */
         $policyService = self::$container->get('app.policy');
         self::$policyService = $policyService;
    }

    public function setUp()
    {
        parent::setUp();
        set_time_limit(1600);
    }

    public function tearDown()
    {
    }

    public function testProcessReferrals()
    {
        $user = Create::user();
        $now = new \DateTime();
        $inviterDate = new \DateTime();
        $inviterDate->sub(new \DateInterval('P30D'));
        $invitee = Create::policy($user, $now, Policy::STATUS_ACTIVE, 12);
        $inviter = Create::policy($user, $inviterDate, Policy::STATUS_ACTIVE, 12);
        $referral = Create::referralBonus($inviter, $invitee, $inviterDate);
        Create::save(static::$dm, $user, $invitee, $inviter, $referral);
        static::$policyService->generateScheduledPayments($invitee);
        static::$policyService->generateScheduledPayments($inviter);
        Create::refresh(static::$dm, $invitee, $inviter);
        $date = new \DateTime();
        $date->add(new \DateInterval('P15D'));
        $result = self::$referralService->processReferrals($date);
        self::$dm->flush();
        $this->assertEquals(2, $result['Applied']);
        $this->assertEquals(ReferralBonus::STATUS_APPLIED, $referral->getStatus());
        $this->assertEquals(true, $referral->getInviterPaid());
        $this->assertEquals(true, $referral->getInviteePaid());
        $this->assertEquals(false, $referral->getInviterCancelled());
    }

    public function testCancelInviteeReferrals()
    {
        $user = Create::user();
        $now = new \DateTime();
        $inviterDate = new \DateTime();
        $inviterDate->sub(new \DateInterval('P30D'));
        $invitee = Create::policy($user, $now, Policy::STATUS_ACTIVE, 12);
        $inviter = Create::policy($user, $inviterDate, Policy::STATUS_ACTIVE, 12);
        $referral = Create::referralBonus($inviter, $invitee, $inviterDate);
        Create::save(static::$dm, $user, $invitee, $inviter, $referral);
        static::$policyService->generateScheduledPayments($invitee);
        static::$policyService->generateScheduledPayments($inviter);
        Create::refresh(static::$dm, $invitee, $inviter);
        $invitee->setStatus(Policy::STATUS_CANCELLED);
        self::$referralService->cancelReferrals($invitee);
        self::$dm->flush();
        $this->assertEquals(ReferralBonus::STATUS_CANCELLED, $referral->getStatus());
        $date = new \DateTime();
        $date->add(new \DateInterval('P15D'));
        $result = self::$referralService->processReferrals($date);
        $this->assertEquals(0, $result['Applied']);
        $this->assertEquals(0, $result['Pending']);
        $this->assertEquals(false, $referral->getInviterPaid());
        $this->assertEquals(false, $referral->getInviteePaid());
        $this->assertEquals(false, $referral->getInviterCancelled());
    }

    public function testCancelInviterReferrals()
    {
        $user = Create::user();
        $now = new \DateTime();
        $inviterDate = new \DateTime();
        $inviterDate->sub(new \DateInterval('P30D'));
        $invitee = Create::policy($user, $now, Policy::STATUS_ACTIVE, 12);
        $inviter = Create::policy($user, $inviterDate, Policy::STATUS_ACTIVE, 12);
        $referral = Create::referralBonus($inviter, $invitee, $inviterDate);
        Create::save(static::$dm, $user, $invitee, $inviter, $referral);
        static::$policyService->generateScheduledPayments($invitee);
        static::$policyService->generateScheduledPayments($inviter);
        Create::refresh(static::$dm, $invitee, $inviter);
        $inviter->setStatus(Policy::STATUS_CANCELLED);
        self::$referralService->cancelReferrals($inviter);
        self::$dm->flush();
        $this->assertEquals(ReferralBonus::STATUS_PENDING, $referral->getStatus());
        $this->assertEquals(true, $referral->getInviterCancelled());
        $date = new \DateTime();
        $date->add(new \DateInterval('P15D'));
        $result = self::$referralService->processReferrals($date);
        self::$dm->flush();
        $this->assertEquals(1, $result['Applied']);
        $this->assertEquals(0, $result['Pending']);
        $this->assertEquals(ReferralBonus::STATUS_APPLIED, $referral->getStatus());
        $this->assertEquals(false, $referral->getInviterPaid());
        $this->assertEquals(true, $referral->getInviteePaid());
    }

    /**
     * We create a monthly policy with no scheduled payments, and try to apply a referral bonus to it. We show that the
     * referral bonus sleeps when the policy to apply to has no scheduled payments, and when that policy renews, it can
     * then be applied.
     */
    public function testSleepMonthly()
    {
        $now = new \DateTime();
        $start = (clone $now)->sub(new \DateInterval('P31D'));
        $end = (clone $start)->add(new \DateInterval('P1Y'));
        $user = Create::user();
        $invitee = Create::policy($user, $start, Policy::STATUS_ACTIVE, 12);
        $inviter = Create::policy($user, $start, Policy::STATUS_ACTIVE, 12);
        $scheduledPayment = Create::standardScheduledPayment(
            $invitee,
            $now,
            ScheduledPayment::STATUS_SCHEDULED,
            ScheduledPayment::TYPE_SCHEDULED
        );
        $referral = Create::referralBonus($inviter, $invitee, $start);
        Create::save(static::$dm, $user, $invitee, $inviter, $referral);
        static::$referralService->processReferrals($now);
        Create::refresh(static::$dm, $invitee, $inviter, $referral);
        $this->assertEquals(ReferralBonus::STATUS_SLEEPING, $referral->getStatus());
        $renewal = Create::policy($user, $end, Policy::STATUS_ACTIVE, 12);
        $scheduledPayment = Create::standardScheduledPayment(
            $renewal,
            $now,
            ScheduledPayment::STATUS_SCHEDULED,
            ScheduledPayment::TYPE_SCHEDULED
        );
        $inviter->link($renewal);
        $inviter->setStatus(Policy::STATUS_EXPIRED);
        Create::save(static::$dm, $renewal, $inviter, $scheduledPayment);
        static::$referralService->processSleepingReferrals($inviter);
        Create::refresh(static::$dm, $invitee, $inviter, $referral, $renewal);
        $this->assertEquals(ReferralBonus::STATUS_APPLIED, $referral->getStatus());
        $this->assertEquals($renewal->getUpgradedStandardMonthlyPrice(), $renewal->getPremiumPaid());
    }

    /**
     * We create a yearly policy which has an entire years worth of referral bonus applied to it. We show that when we
     * try to apply another referral bonus it goes to sleep, and then when the policy renews it can be applied.
     */
    public function testSleepYearly()
    {
        $now = new \DateTime();
        $start = (clone $now)->sub(new \DateInterval('P31D'));
        $end = (clone $start)->add(new \DateInterval('P1Y'));
        $user = Create::user();
        $inviter = Create::bacsPolicy($user, $start, Policy::STATUS_ACTIVE, 1);
        Create::save(static::$dm, $user, $inviter);
        for ($i = 0; $i < 11; $i++) {
            $otherUser = Create::user();
            $invitee = Create::policy($otherUser, $start, Policy::STATUS_ACTIVE, 12);
            $scheduledPayment = Create::standardScheduledPayment(
                $invitee,
                $now,
                ScheduledPayment::STATUS_SCHEDULED,
                ScheduledPayment::TYPE_SCHEDULED
            );
            $referral = Create::referralBonus($inviter, $invitee, $start);
            Create::save(static::$dm, $otherUser, $scheduledPayment, $invitee, $referral);
        }
        static::$referralService->processReferrals($now);
        Create::refresh(static::$dm, $inviter);
        $this->assertEquals(0, $inviter->getPremiumPaid() + $inviter->getTotalScheduled());
        // Now do the final one.
        $otherUser = Create::user();
        $invitee = Create::policy($otherUser, $start, Policy::STATUS_ACTIVE, 12);
        $scheduledPayment = Create::standardScheduledPayment(
            $invitee,
            $now,
            ScheduledPayment::STATUS_SCHEDULED,
            ScheduledPayment::TYPE_SCHEDULED
        );
        $referral = Create::referralBonus($inviter, $invitee, $start);
        Create::save(static::$dm, $otherUser, $invitee, $referral);
        static::$referralService->processReferrals($now);
        Create::refresh(static::$dm, $referral);
        $this->assertEquals(ReferralBonus::STATUS_SLEEPING, $referral->getStatus());
        // Do the final one with the renewal.
        $renewal = Create::bacsPolicy($user, $end, Policy::STATUS_ACTIVE, 1);
        $inviter->link($renewal);
        $inviter->setStatus(Policy::STATUS_EXPIRED);
        Create::save(static::$dm, $renewal, $inviter);
        static::$referralService->processSleepingReferrals($inviter);
        Create::refresh(static::$dm, $renewal, $inviter, $referral);
        $this->assertEquals(ReferralBonus::STATUS_APPLIED, $referral->getStatus());
        $this->assertEquals(
            $this->toTwoDp($renewal->getUpgradedYearlyPrice() / 11),
            $this->toTwoDp($renewal->getPremiumPaid())
        );
    }
}
