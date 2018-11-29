<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\DateTrait;
use AppBundle\Service\PromotionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Service\MailerService;
use Psr\Log\LoggerInterface;


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

    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        static::$container = $kernel->getContainer();
        /** @var PromotionService $affiliateService */
        $promotionService = static::$container->get('app.promotion');
        static::$promotionService = $promotionService;
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
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
        $a = $this->createUserPolicy();
        $b = $this->createUserPolicy();
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
    public function testGenerateSuccess()
    {
        $promotion = $this->createTestPromotion(
            "free!!! phone case!!!!!!!!!",
            Promotion::CONDITION_NONE,
            Promotion::REWARD_TASTE_CARD,
            30,
            1,
            0
        );
        $a = $this->createUserPolicy();
        $b = $this->createUserPolicy();
        $date = new \DateTime();
        // no condition and period not passed.
        $aParticipation = $this->participate($promotion, $a, $date);
        $this->addDays($date, 5);
        $bParticipation = $this->participate($promotion, $b, new \DateTime());
        $mock = $this->mockMailerSend(0);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_ACTIVE, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        // no condition and period passed.
        $this->addDays($date, 30);
        $mock = $this->mockMailerSend(1);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_ACTIVE, $bParticipation->getStatus());
        $this->addDays($date, 5);
        $mock = $this->mockMailerSend(1);
        static::$promotionService->generate([$promotion], $date);
        $mock->__phpunit_verify();
        $this->assertEquals(Participation::STATUS_COMPLETED, $aParticipation->getStatus());
        $this->assertEquals(Participation::STATUS_COMPLETED, $bParticipation->getStatus());
    }

    public function testGenerateInvalid()
    {

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
        return $participation;
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
        return $promotion;
    }

    private function mockMailerSend($times)
    {
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->exactly($times))->method('sendTemplate');
        static::$promotionService = new PromotionService(static::$dm, $mailer, $this->createMock(LoggerInterface::class));
        return $mailer;
    }
}
