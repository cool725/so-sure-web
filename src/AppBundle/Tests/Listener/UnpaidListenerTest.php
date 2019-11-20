<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Service\SmsService;
use AppBundle\Service\MailerService;
use AppBundle\Service\FeatureService;
use AppBundle\Listener\UnpaidListener;
use AppBundle\Event\ScheduledPaymentEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Repository\ScheduledPaymentRepository;
use Psr\Log\LoggerInterface;

/**
 * Tests that the unpaid listener sends out the right emails at the right times.
 * @group functional-net
 * AppBundle\\Tests\\Listener\\UnpaidListenerTest
 */
class UnpaidListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
    protected static $scheduledPaymentRepo;

    /**
     * Sets up general stuff.
     */
    public static function setUpBeforeClass()
    {
        $kernel = self::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        self::$scheduledPaymentRepo = $scheduledPaymentRepo;
    }

    /**
     * Tests the bacs unpaid comms with and without claims, and makes sure that scheduled payments
     * are rescheduled to the right dates.
     */
    public function testBacsUnpaid()
    {
        $policy = $this->payingPolicy(new BacsPaymentMethod());
        $scheduledPayment = $this->payment($policy, new \DateTime(), ScheduledPayment::STATUS_REVERTED);
        $listener = $this->mockedListener(
            1,
            1,
            "AppBundle:Email:bacs/bacsPaymentFailed-1.html.twig",
            "AppBundle:Sms:bacs/failedPayment-1.txt.twig"
        );
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));

        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(
            1,
            1,
            "AppBundle:Email:bacs/bacsPaymentFailed-2.html.twig",
            "AppBundle:Sms:bacs/failedPayment-2.txt.twig"
        );
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));

        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(
            1,
            1,
            "AppBundle:Email:bacs/bacsPaymentFailed-3.html.twig",
            "AppBundle:Sms:bacs/failedPayment-3.txt.twig"
        );
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));

        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(
            1,
            1,
            "AppBundle:Email:bacs/bacsPaymentFailed-4.html.twig",
            "AppBundle:Sms:bacs/failedPayment-4.txt.twig"
        );
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
    }

    // TODO: testCheckoutUnpaid()

    /**
     * Tests the judo unpaid comms with no claims and a card.
     */
    public function testJudoUnpaid()
    {
        $paymentMethod = new JudoPaymentMethod();
        $paymentMethod->setCustomerToken('ctoken');
        $paymentMethod->addCardToken('token', json_encode(['endDate' => "0150"]));
        $policy = $this->payingPolicy($paymentMethod);
        $scheduledPayment = $this->payment($policy, new \DateTime(), ScheduledPayment::STATUS_FAILED);
        $listener = $this->mockedListener(1, 0, "AppBundle:Email:card/failedPayment-1.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/failedPayment-2.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/failedPayment-3.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/failedPayment-4.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        // Do nothing from that point.
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(0, 0);
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
    }

    /**
     * Tests the judo unpaid comms when policy has claimed.
     */
    public function testJudoUnpaidClaimed()
    {
        $paymentMethod = new JudoPaymentMethod();
        $paymentMethod->setCustomerToken('ctoken');
        $paymentMethod->addCardToken('token', json_encode(['endDate' => "0150"]));
        $policy = $this->payingPolicy($paymentMethod);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNumber(uniqid());
        $claim->setSubmissionDate(new \DateTime());
        $policy->addClaim($claim);
        self::$dm->persist($claim);
        self::$dm->flush();
        $scheduledPayment = $this->payment($policy, new \DateTime(), ScheduledPayment::STATUS_FAILED);
        $listener = $this->mockedListener(1, 0, "AppBundle:Email:card/failedPaymentWithClaim-1.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/failedPaymentWithClaim-2.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/failedPaymentWithClaim-3.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/failedPaymentWithClaim-4.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        // Do nothing from that point.
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(0, 0);
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
    }

    /**
     * Tests the judo unpaid comms when user has removed their card.
     */
    public function testJudoUnpaidNoCard()
    {
        $policy = $this->payingPolicy(new JudoPaymentMethod());
        $scheduledPayment = $this->payment($policy, new \DateTime(), ScheduledPayment::STATUS_FAILED);
        $listener = $this->mockedListener(1, 0, "AppBundle:Email:card/cardMissing-1.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/cardMissing-2.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/cardMissing-3.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(1, 1, "AppBundle:Email:card/cardMissing-4.html.twig");
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
        // Do nothing from that point.
        $scheduledPayment = self::$scheduledPaymentRepo->mostRecentWithStatuses($policy);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        self::$dm->flush();
        $listener = $this->mockedListener(0, 0);
        $listener->onUnpaidEvent(new ScheduledPaymentEvent($scheduledPayment));
    }

    /**
     * Creates an unpaid listener where the sms service and mailer service are both mocks, and they may or may not
     * expect for emails to be sent.
     * @param int    $mailCount is the number of emails expected to be sent.
     * @param int    $smsCount  is the number of smses expected to be sent.
     * @param string $mailBody  is text that the email html body is expected to contain.
     * @param string $smsBody   is text that the sms body is expected to contain.
     * @return UnpaidListener that has these two mocked services inside it's body.
     */
    private function mockedListener(
        $mailCount,
        $smsCount,
        $mailBody = null,
        $smsBody = null
    ) {
        $mailer = $this->mockMailer($mailBody, $mailCount);
        $smser = $this->mockSmser($smsBody, $smsCount);
        $feature = $this->getMockBuilder(FeatureService::class)
            ->disableOriginalConstructor()
            ->setMethods(["isEnabled"])
            ->getMock();
        $feature->expects($this->any())->method("isEnabled")->willReturn(false);
        /** @var FeatureService $feature */
        $feature = $feature;
        $logger = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(["error"])
            ->getMock();
        /** @var LoggerInterface $logger */
        $logger = $logger;
        return new UnpaidListener(self::$dm, $mailer, $smser, $feature, $logger);
    }

    /**
     * Creates a mocked mailer.
     * @param string $body  is the text to look for in the mail body.
     * @param int    $count is the number of email sends to expect.
     * @return MailerService mocked version of the mailer service that will be expecting some arguments.
     */
    private function mockMailer($body = null, $count = 1)
    {
        $mailer = $this->getMockBuilder(MailerService::class)
            ->disableOriginalConstructor()
            ->setMethods(["sendTemplateToUser"])
            ->getMock();
        $mailer->expects($count ? $this->exactly($count) : $this->never())
            ->method("sendTemplateToUser")
            ->with(
                $this->anything(),
                $this->anything(),
                $body ? $this->equalTo($body) : $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );
        /** @var MailerService mailer */
        $mailer = $mailer;
        return $mailer;
    }

    /**
     * Makes a mocked sms service that expects to be asked to send an sms with given content in it's body text.
     * @param string $body  is the text that is expected to be found in the body.
     * @param int    $count is the number of sms sends to expect.
     * @return SmsService which is a fake.
     */
    private function mockSmser($body = null, $count = 1)
    {
        $smser = $this->getMockBuilder(SmsService::class)
            ->disableOriginalConstructor()
            ->setMethods(["sendUser"])
            ->getMock();
        $smser->expects($count ? $this->exactly($count) : $this->never())
            ->method("sendUser")
            ->with(
                $this->anything(),
                $body ? $this->stringContains($body) : $this->anything(),
                $this->anything()
            );
        /** @var SmsService $smser */
        $smser = $smser;
        return $smser;
    }

    /**
     * Creates a policy and user and gives the user a provided payment method. Also it persists and flushes the payment
     * method so you can just pass that in.
     * @param PaymentMethod $method is the payment method to give the user.
     * @return Policy the new policy.
     */
    private function payingPolicy($method)
    {
        $policy = $this->createUserPolicy(true, null, false, uniqid()."@yahoo.com");
        $policy->setPaymentMethod($method);
        self::$dm->persist($policy);
        self::$dm->persist($method);
        self::$dm->persist($policy->getUser());
        self::$dm->flush();
        return $policy;
    }

    /**
     * Creates a scheduled payment at a given date with a given status on the given policy.
     * @param Policy    $policy is the policy that the payment is for.
     * @param \DateTime $date   is the date that the payment is scheduled for.
     * @param string    $status is the status that the payment will be set to.
     * @return ScheduledPayment the payment.
     */
    private function payment($policy, $date, $status)
    {
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setScheduled($date);
        $scheduledPayment->setStatus($status);
        $policy->addScheduledPayment($scheduledPayment);
        // Add another payment scheduled a month later to make sure it does not interfere.
        $otherScheduledPayment = new ScheduledPayment();
        $otherScheduledPayment->setScheduled($date);
        $otherScheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $policy->addScheduledPayment($otherScheduledPayment);
        self::$dm->persist($scheduledPayment);
        self::$dm->persist($otherScheduledPayment);
        self::$dm->flush();
        return $scheduledPayment;
    }
}
