<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\PromotionService;
use AppBundle\Service\ClaimsService;
use AppBundle\Service\InvitationService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Service\MailerService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group functional-nonet
 * @group fixed
 *
 * AppBundle\\Tests\\Service\\SCodeServiceTest
 */
class PromotionServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var PromotionService */
    protected static $promotionService;
    /** @var ClaimsService */
    protected static $claimsService;
    /** @var InvitationService */
    protected static $invitationService;

    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        static::$container = $kernel->getContainer();
        /** @var PromotionService $promotionService */
        $promotionService = static::$container->get("app.promotion");
        static::$promotionService = $promotionService;
        /** @var ClaimsService $claimsService */
        $claimsService = static::$container->get("app.claims");
        static::$claimsService = $claimsService;
        /** @var InvitationService */
        $invitationService = static::$container->get("app.invitation");
        static::$invitationService = $invitationService;
        /** @var DocumentManager */
        $dm = self::$container->get("doctrine_mongodb.odm.default_document_manager");
        self::$dm = $dm;
    }

    /**
     * Tests the promotion service generate method.
     */
    public function testGeneratePromotion()
    {
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            30,
            0,
            false,
            Promotion::REWARD_TASTE_CARD
        );
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $date = new \DateTime();
        // no condition and period not passed.
        $aParticipation = $this->participate($promotion, $a, $date);
        $date = $this->addDays($date, 5);
        $bParticipation = $this->participate($promotion, $b, $date);
        $mock = $this->mockMailerSend(0);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_ACTIVE, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        // no condition and period passed.
        $date = $this->addDays($date, 25);
        $mock = $this->mockMailerSend(1);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        $date = $this->addDays($date, 5);
        $mock = $this->mockMailerSend(1);
        $changes = static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(1, $changes[Participation::STATUS_COMPLETED]);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_COMPLETED, $bParticipation->getStatus());
        // Make sure once it's all done no more stuff happens.
        $date = $this->addDays($date, 30);
        $mock = $this->mockMailerSend(0);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        // Add an invalid participation
        $c = $this->createPersistentUser();
        $cParticipation = $this->participate($promotion, $c, $date);
        $date = $this->addDays($date, 5);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_ACTIVE, $cParticipation->getStatus());
        $date = $this->addDays($date, 5);
        $c->setTasteCard("asdfghjklp");
        $mock = $this->mockMailerSend(1);
        $changes = static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(1, $changes[Participation::STATUS_INVALID]);
        $this->assertEquals(Participation::STATUS_INVALID, $cParticipation->getStatus());
        // Add a failure.
        $d = $this->createPersistentUser();
        $dParticipation = $this->participate($promotion, $d, $date);
        $mock = $this->mockMailerSend(0);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_ACTIVE, $dParticipation->getStatus());
        $this->claim($d, $date);
        $mock = $this->mockMailerSend(0);
        $changes = static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(1, $changes[Participation::STATUS_FAILED]);
        $this->assertEquals(Participation::STATUS_FAILED, $dParticipation->getStatus());
    }

    /**
     * Runs the rewardConditions method directly and tests that it outputs the right stuff.
     */
    public function testRewardConditions()
    {
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            30,
            0,
            true,
            Promotion::REWARD_TASTE_CARD
        );
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $c = $this->createPersistentUser();
        $date = new \DateTime("1208-02-05 17:33");
        $aParticipation = $this->participate($promotion, $a, $date);
        $bParticipation = $this->participate($promotion, $b, $date);
        $cParticipation = $this->participate($promotion, $c, $date);
        $this->claim($b, $date);
        $this->invite($b, static::generateEmail('areg', $this), $date);
        $this->invite($c, static::generateEmail('areg', $this), $date);
        // no conditions
        $this->assertNull(static::$promotionService->rewardConditions($aParticipation, $date));
        $this->assertNull(static::$promotionService->rewardConditions($bParticipation, $date));
        $this->assertNull(static::$promotionService->rewardConditions($cParticipation, $date));
        $date = $this->addDays($date, 29);
        $this->assertNull(static::$promotionService->rewardConditions($aParticipation, $date));
        $this->assertNull(static::$promotionService->rewardConditions($bParticipation, $date));
        $this->assertNull(static::$promotionService->rewardConditions($cParticipation, $date));
        $date = $this->addDays($date, 1);
        $this->assertEquals(
            Participation::STATUS_COMPLETED,
            static::$promotionService->rewardConditions($aParticipation, $date)
        );
        $this->assertEquals(
            Participation::STATUS_COMPLETED,
            static::$promotionService->rewardConditions($bParticipation, $date)
        );
        $this->assertEquals(
            Participation::STATUS_COMPLETED,
            static::$promotionService->rewardConditions($cParticipation, $date)
        );
        // add ban on claims.
        $promotion->setConditionPeriod(40);
        $promotion->setConditionAllowClaims(false);
        $this->assertNull(static::$promotionService->rewardConditions($aParticipation, $date));
        $this->assertEquals(
            Participation::STATUS_FAILED,
            static::$promotionService->rewardConditions($bParticipation, $date)
        );
        $this->assertNull(static::$promotionService->rewardConditions($cParticipation, $date));
        // add mandatory invitation.
        $promotion->setConditionInvitations(1);
        $this->assertNull(static::$promotionService->rewardConditions($aParticipation, $date));
        $this->assertEquals(
            Participation::STATUS_FAILED,
            static::$promotionService->rewardConditions($bParticipation, $date)
        );
        $this->assertNull(static::$promotionService->rewardConditions($cParticipation, $date));
        $date = $this->addDays($date, 10);
        $this->assertEquals(
            Participation::STATUS_FAILED,
            static::$promotionService->rewardConditions($aParticipation, $date)
        );
        $this->assertEquals(
            Participation::STATUS_FAILED,
            static::$promotionService->rewardConditions($bParticipation, $date)
        );
        $this->assertEquals(
            Participation::STATUS_COMPLETED,
            static::$promotionService->rewardConditions($cParticipation, $date)
        );
    }

    /**
     * Tests that the invalidation conditions function points out the right invalid participations.
     */
    public function testInvalidationConditions()
    {
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            30,
            0,
            true,
            Promotion::REWARD_TASTE_CARD
        );
        $date = new \DateTime("2018-05-19 11:02");
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $aParticipation = $this->participate($promotion, $a, $date);
        $bParticipation = $this->participate($promotion, $b, $date);
        $this->assertNull(static::$promotionService->invalidationConditions($aParticipation));
        $this->assertNull(static::$promotionService->invalidationConditions($bParticipation));
        $a->setTasteCard("1234567890");
        $this->assertEquals(
            Participation::INVALID_EXISTING_TASTE_CARD,
            static::$promotionService->invalidationConditions($aParticipation)
        );
    }

    /**
     * Tests the promotion service end participation function for completed participations and failed ones as well.
     */
    public function testEndParticipation()
    {
        $date = new \DateTime();
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            30,
            0,
            false,
            Promotion::REWARD_TASTE_CARD
        );
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $aParticipation = $this->participate($promotion, $a, new \DateTime());
        $bParticipation = $this->participate($promotion, $b, new \DateTime());
        // Test completed participation.
        $mock = $this->mockMailerSend(1);
        static::$promotionService->endParticipation($aParticipation, Participation::STATUS_COMPLETED, $date);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals($date, $aParticipation->getEnd());
        $mock->__phpunit_verify();
        // Test failed participation.
        $mock = $this->mockMailerSend(0);
        static::$promotionService->endParticipation($bParticipation, Participation::STATUS_FAILED, $date);
        $this->assertEquals(Participation::STATUS_FAILED, $bParticipation->getStatus());
        $this->assertEquals($date, $bParticipation->getEnd());
        $mock->__phpunit_verify();
    }

    /**
     * tests to make sure when you invalidate a participation the email sends and the status and date are set.
     */
    public function testInvalidateParticipation()
    {
        $date = new \DateTime();
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            30,
            0,
            false,
            Promotion::REWARD_TASTE_CARD
        );
        $user = $this->createPersistentUser();
        $participation = $this->participate($promotion, $user, new \DateTime());
        $mock = $this->mockMailerSend(1);
        static::$promotionService->invalidateParticipation(
            $participation,
            Participation::INVALID_EXISTING_TASTE_CARD,
            $date
        );
        $this->assertEquals(Participation::STATUS_INVALID, $participation->getStatus());
        $this->assertEquals($date, $participation->getEnd());
        $mock->__phpunit_verify();
    }

    /**
     * writes an invite.
     * @param Policy    $policy is the policy to create an invite in the name of.
     * @param String    $email  is the email account to send the invite to.
     * @param \DateTime $date   is the date to write the invite at.
     */
    private function invite($policy, $email, $date)
    {
        $invitation = new EmailInvitation();
        $invitation->setEmail($email);
        $policy->addInvitation($invitation);
        $invitation->setName($email);
        $invitation->setCreated(clone $date);
        $invitation->invite();
        static::$dm->persist($invitation);
        static::$dm->flush();
    }

    /**
     * Adds a claim to the given policy.
     * @param Policy    $policy is the policy we  are adding the claim to.
     * @param \DateTime $date   is the date that the claim should be set at.
     */
    private function claim($policy, $date)
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNumber(uniqid());
        $claim->setSubmissionDate(clone $date);
        static::$claimsService->addClaim($policy, $claim);
        static::$dm->persist($claim);
        static::$dm->flush();
    }

    /**
     * Enters a policy into a promotion at a given time.
     * @param Promotion $promotion is the promotion.
     * @param Policy    $policy    is the policy.
     * @param \DateTime $date      is the date of entry.
     * @return Participation the new particiation.
     */
    private function participate($promotion, $policy, $date)
    {
        $participation = new Participation();
        $participation->setPolicy($policy);
        $participation->setStart(clone $date);
        $participation->setStatus(Participation::STATUS_ACTIVE);
        $promotion->addParticipating($participation);
        static::$dm->persist($participation);
        static::$dm->flush();
        return $participation;
    }

    /**
     * Creates a user and a policy and persists them.
     * @return Policy the created policy.
     */
    private function createPersistentUser()
    {
        $policy = $this->createUserPolicy();
        $user = $policy->getUser();
        $user->setFirstName(uniqid());
        $user->setLastName(uniqid());
        $user->setEmail(static::generateEmail(uniqid(), $this));
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();
        return $policy;
    }

    /**
     * Creates a promotion object for a nice test.
     * @param String $name        is the name of the promotion.
     * @param int    $period      is the maximum number of days that you can be active in the promotion for.
     * @param int    $invitations is the number of events required by the condition.
     * @param bool   $claims      is the number of events required by the condition.
     * @param String $reward      is the reward constant that this promotion gives.
     * @param float  $amount      is the quantity of the reward if it is a reward that needs a quantity.
     * @return Promotion the new promotion.
     */
    private function createTestPromotion($name, $period, $invitations, $claims, $reward, $amount = 1.37)
    {
        $promotion = new Promotion();
        $promotion->setName($name);
        $promotion->setStart(new \DateTime());
        $promotion->setActive(true);
        $promotion->setConditionPeriod($period);
        $promotion->setConditionInvitations($invitations);
        $promotion->setConditionAllowClaims($claims);
        $promotion->setReward($reward);
        $promotion->setRewardAmount($amount);
        static::$dm->persist($promotion);
        static::$dm->flush();
        return $promotion;
    }

    /**
     * Tells the mock mailer to anticipate n many emails to occur.
     * @param int $times is the number of times an email should be sent.
     * @return MockObject the mocked mailer.
     */
    private function mockMailerSend($times)
    {

        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->exactly($times))->method('sendTemplate');
        /** @var MailerService $mailer */
        $mailer = $mailer;
        static::$promotionService = new PromotionService(static::$dm, $mailer, static::$container->get("logger"));
        /** @var MockObject $mailer this is silly but the standards test requires it */
        $mailer = $mailer;
        return $mailer;
    }
}
