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
     * Tests the promotion service end participation function for completed participations and failed ones as well.
     */
    public function testEndParticipation()
    {
        $promotion = $this->createTestPromotion(
            "free taste card",
            Promotion::CONDITION_INVITES,
            Promotion::REWARD_TASTE_CARD,
            30,
            1,
            0
        );
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $aParticipation = $this->participate($promotion, $a, new \DateTime());
        $bParticipation = $this->participate($promotion, $b, new \DateTime());
        // Test completed participation.
        $mock = $this->mockMailerSend(1);
        static::$promotionService->endParticipation($aParticipation);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $mock->__phpunit_verify();
        // Test failed participation.
        $mock = $this->mockMailerSend(0);
        static::$promotionService->endParticipation($bParticipation, Participation::STATUS_FAILED);
        $this->assertEquals(Participation::STATUS_FAILED, $bParticipation->getStatus());
        $mock->__phpunit_verify();
    }

    /**
     * Tests the promotion service generate method.
     */
    public function testGenerate()
    {
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            Promotion::CONDITION_NO_CLAIMS,
            Promotion::REWARD_TASTE_CARD,
            30,
            1,
            0
        );
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $date = new \DateTime();
        // no condition and period not passed.
        $aParticipation = $this->participate($promotion, $a, $date);
        $this->addDays($date, 5);
        $bParticipation = $this->participate($promotion, $b, $date);
        $mock = $this->mockMailerSend(0);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_ACTIVE, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        // no condition and period passed.
        $this->addDays($date, 25);
        $mock = $this->mockMailerSend(1);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        $this->addDays($date, 5);
        $mock = $this->mockMailerSend(1);
        $changes = static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(1, $changes[Participation::STATUS_COMPLETED]);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_COMPLETED, $bParticipation->getStatus());
        // Make sure once it's all done no more stuff happens.
        $this->addDays($date, 30);
        $mock = $this->mockMailerSend(0);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        // Add an invalid participation
        $c = $this->createPersistentUser();
        $cParticipation = $this->participate($promotion, $c, $date);
        $this->addDays($date, 5);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_ACTIVE, $cParticipation->getStatus());
        $this->addDays($date, 5);
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
     * Tests all condition forms and all outcomes those conditions can produce.
     */
    public function testCheckConditions()
    {
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            Promotion::CONDITION_NO_CLAIMS,
            Promotion::REWARD_TASTE_CARD,
            30,
            2,
            0
        );
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $date = new \DateTime();
        $aParticipation = $this->participate($promotion, $a, $date);
        $this->addDays($date, 5);
        $bParticipation = $this->participate($promotion, $b, $date);
        // Check no claims condition.
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_ACTIVE, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        $this->addDays($date, 25);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        $this->claim($b, $date);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_FAILED, $bParticipation->getStatus());
        $this->addDays($date, 30);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_FAILED, $bParticipation->getStatus());
        // Check X invitations condition.
        $promotion->setCondition(Promotion::CONDITION_INVITES);
        $c = $this->createPersistentUser();
        $d = $this->createPersistentUser();
        $cParticipation = $this->participate($promotion, $c, $date);
        $this->addDays($date, 10);
        $dParticipation = $this->participate($promotion, $d, $date);
        $this->addDays($date, 10);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_ACTIVE, $cParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $dParticipation->getStatus());
        $this->invite($c, static::generateEmail('areg', $this), $date);
        $this->invite($d, static::generateEmail('areg', $this), $date);
        $this->addDays($date, 10);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_FAILED, $cParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $dParticipation->getStatus());
        $this->invite($d, static::generateEmail('aewrgreg', $this), $date);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_FAILED, $cParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_COMPLETED, $dParticipation->getStatus());
        // Check no condition.
        $promotion->setCondition(Promotion::CONDITION_NONE);
        $e = $this->createPersistentUser();
        $f = $this->createPersistentUser();
        $eParticipation = $this->participate($promotion, $e, $date);
        $this->addDays($date, 10);
        $fParticipation = $this->participate($promotion, $f, $date);
        $this->addDays($date, 10);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_ACTIVE, $eParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $fParticipation->getStatus());
        $this->addDays($date, 10);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_COMPLETED, $eParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $fParticipation->getStatus());
        $this->addDays($date, 10);
        static::$promotionService->generate([$promotion], $date);
        $this->assertEquals(Participation::STATUS_COMPLETED, $eParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_COMPLETED, $fParticipation->getStatus());
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
     * @param String $name      is the name of the promotion.
     * @param String $condition is the condition constant that this promotion uses.
     * @param String $reward    is the reward constant that this promotion gives.
     * @param int    $period    is the maximum number of days that you can be active in the promotion for.
     * @param int    $events    is the number of events required by the condition.
     * @param float  $amount    is the quantity of the reward if it is a reward that needs a quantity.
     * @return Promotion the new promotion.
     */
    private function createTestPromotion($name, $condition, $reward, $period, $events = 0, $amount = 0)
    {
        $promotion = new Promotion();
        $promotion->setName($name);
        $promotion->setStart(new \DateTime());
        $promotion->setActive(true);
        $promotion->setCondition($condition);
        $promotion->setReward($reward);
        $promotion->setPeriod($period);
        $promotion->setConditionEvents($events);
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
