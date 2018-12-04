<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\PhonePremium;
use AppBundle\Event\PolicyEvent;
use AppBundle\Listener\TasteCardListener;
use AppBundle\Service\MailerService;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group functional-net
 */
class TasteCardListenerTest extends WebTestCase
{
    use UserClassTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $policyService;

    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        /** @var DocumentManager */
        $dm = self::$container->get("doctrine_mongodb.odm.default_document_manager");
        self::$dm = $dm;
        self::$policyService = self::$container->get("app.policy");
    }

    public function tearDown()
    {
    }

    /**
     * Tests that the correct action is performed when the on policy cancelled event function is called on the listener.
     */
    public function testOnPolicyCancelledEvent()
    {
        $tasteCard = "1234567890";
        $mailerService = $this->createMock(MailerService::class);
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $a->setTasteCard($tasteCard);
        static::$policyService->cancel($a, Policy::CANCELLED_DISPOSSESSION);
        static::$policyService->cancel($b, Policy::CANCELLED_DISPOSSESSION);
        $aEvent = new PolicyEvent($a);
        $bEvent = new PolicyEvent($b);
        // no tastecard so no email.
        $mailerService->expects($this->exactly(0))->method("sendTemplate");
        $tasteCardListener = new TasteCardListener($mailerService);
        $tasteCardListener->onPolicyCancelledEvent($bEvent);
        $mailerService->__phpunit_verify();
        // there is a tastecard so there should also be an email.
        $mailerService->expects($this->exactly(1))->method("sendTemplate");
        $tasteCardListener = new TasteCardListener($mailerService);
        $tasteCardListener->onPolicyCancelledEvent($aEvent);
        $mailerService->__phpunit_verify();
        // Test is for mailer. This is just so the test is not reported as risky.
        $this->assertEquals(1, 1);
    }

    /**
     * Creates a user and a policy and persists them.
     * @return Policy the created policy.
     */
    private function createPersistentUser()
    {
        $policy = self::createUserPolicy(true, new \DateTime());
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setImei(self::generateRandomImei());
        $user = $policy->getUser();
        $user->setFirstName(uniqid());
        $user->setLastName(uniqid());
        $user->setEmail(static::generateEmail(uniqid(), $this));
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();
        return $policy;
    }
}
