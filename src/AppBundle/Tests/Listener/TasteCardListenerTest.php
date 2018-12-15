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
 * @group functional-nonet
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
        $a = $this->createPersistentUser();
        $b = $this->createPersistentUser();
        $a->setTasteCard($tasteCard);
        static::$policyService->cancel($a, Policy::CANCELLED_DISPOSSESSION);
        static::$policyService->cancel($b, Policy::CANCELLED_DISPOSSESSION);
        $aEvent = new PolicyEvent($a);
        $bEvent = new PolicyEvent($b);
        // no tastecard so no email.
        $this->verifyEvent($bEvent, 0);
        // there is a tastecard so there should also be an email.
        $this->verifyEvent($aEvent, 1);
        // Test is for mailer. This is just so the test is not reported as risky.
        $this->assertEquals(1, 1);
    }

    /**
     * Verifies that the taste card listener service sends the right number of emails out on a given event.
     * @param PolicyEvent $event       is the event being sent to the listener.
     * @param int         $expectation is the number of emails we anticipate will be sent.
     */
    private function verifyEvent($event, $expectation)
    {
        /** @var MockObject $mailerService */
        $mailerService = $this->createMock(MailerService::class);
        $mailerService->expects($this->exactly($expectation))->method("sendTemplate");
        /** @var MailerService $mailerService */
        $mailerService = $mailerService;
        $tasteCardListener = new TasteCardListener($mailerService);
        $tasteCardListener->onPolicyCancelledEvent($event);
        /** @var MockObject $mailerService */
        $mailerService = $mailerService;
        $mailerService->__phpunit_verify();
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
