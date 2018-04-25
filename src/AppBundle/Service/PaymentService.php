<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\DirectDebitNotificationFile;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Repository\ScheduledPaymentRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\UserBundle\Mailer\Mailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Templating\EngineInterface;

class PaymentService
{
    /** @var JudopayService $judopay */
    protected $judopay;

    /** @var LoggerInterface $logger */
    protected $logger;

    /** @var DocumentManager $dm */
    protected $dm;

    /** @var MailerService $mailer */
    protected $mailer;

    /** @var SequenceService $sequenceService */
    protected $sequenceService;

    /** @var string */
    protected $environment;

    /** @var FraudService */
    protected $fraudService;

    /** @var EngineInterface */
    protected $templating;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * PaymentService constructor.
     * @param JudopayService           $judopay
     * @param LoggerInterface          $logger
     * @param DocumentManager          $dm
     * @param SequenceService          $sequenceService
     * @param MailerService            $mailer
     * @param string                   $environment
     * @param FraudService             $fraudService
     * @param EngineInterface          $templating
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(
        JudopayService $judopay,
        LoggerInterface $logger,
        DocumentManager $dm,
        SequenceService $sequenceService,
        MailerService $mailer,
        $environment,
        FraudService $fraudService,
        EngineInterface $templating,
        EventDispatcherInterface $dispatcher
    ) {
        $this->judopay = $judopay;
        $this->logger = $logger;
        $this->dm = $dm;
        $this->mailer = $mailer;
        $this->sequenceService = $sequenceService;
        $this->environment = $environment;
        $this->fraudService = $fraudService;
        $this->templating = $templating;
        $this->dispatcher = $dispatcher;
    }

    public function getAllValidScheduledPaymentsForType($prefix, $type, \DateTime $scheduledDate = null)
    {
        $results = [];

        /** @var ScheduledPaymentRepository $repo */
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $repo->findScheduled($scheduledDate);
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            if (!$scheduledPayment->isBillable()) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->isValidPolicy($prefix)) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->getPayerOrUser()) {
                $this->logger->warning(sprintf(
                    'Policy %s/%s does not have a user (payer)',
                    $scheduledPayment->getPolicy()->getPolicyNumber(),
                    $scheduledPayment->getPolicy()->getId()
                ));
                continue;
            }
            if (!$scheduledPayment->getPolicy()->getPayerOrUser()->getPaymentMethod() instanceof $type) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->getPayerOrUser()->hasValidPaymentMethod()) {
                $this->logger->warning(sprintf(
                    'User %s does not have a valid payment method',
                    $scheduledPayment->getPolicy()->getPayerOrUser()->getId()
                ));
            }

            $results[] = $scheduledPayment;
        }

        return $results;
    }

    public function scheduledPayment(
        ScheduledPayment $scheduledPayment,
        $prefix = null,
        \DateTime $date = null,
        $abortOnMultipleSameDayPayment = true
    ) {
        $scheduledPayment->validateRunable($prefix, $date);

        $policy = $scheduledPayment->getPolicy();
        $paymentMethod = $policy->getPayerOrUser()->getPaymentMethod();
        if ($paymentMethod && $paymentMethod instanceof JudoPaymentMethod) {
            return $this->judopay->scheduledPayment(
                $scheduledPayment,
                $prefix,
                $date,
                $abortOnMultipleSameDayPayment
            );
        } else {
            throw new \Exception(sprintf(
                'Payment method not valid for scheduled payment %s',
                $scheduledPayment->getId()
            ));
        }
    }

    /**
     * @param Bacs $bacs
     * @param User $user
     * @return string
     * @throws \Exception
     */
    public function generateBacsReference(Bacs $bacs, User $user)
    {
        if ($this->environment == 'prod') {
            $seq = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_REFERENCE);
        } else {
            $seq = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_REFERENCE_INVALID);
        }
        $ref = $bacs->generateReference($user, $seq);

        return $ref;
    }

    /**
     * @param Policy            $policy
     * @param BacsPaymentMethod $bacsPaymentMethod
     */
    public function confirmBacs(Policy $policy, BacsPaymentMethod $bacsPaymentMethod)
    {
        $policy->getUser()->setPaymentMethod($bacsPaymentMethod);
        $bacsPaymentMethod->getBankAccount()->setInitialNotificationDate(
            $bacsPaymentMethod->getBankAccount()->getFirstPaymentDate($policy->getUser())
        );
        $bacsPaymentMethod->getBankAccount()->setStandardNotificationDate($policy->getBilling());
        // ensure payer is current user for bacs
        if ($policy->isDifferentPayer()) {
            $policy->setPayer($policy->getUser());
        }
        $this->dm->flush();

        $this->mailer->sendTemplate(
            sprintf('Your Direct Debit Confirmation'),
            $policy->getUser()->getEmail(),
            'AppBundle:Email:bacs/notification.html.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            'AppBundle:Email:bacs/notification.txt.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            null,
            'bcc-ddnotifications@so-sure.com'
        );

        if ($this->fraudService->getDuplicateBankAccounts($policy) > 0) {
            $this->mailer->send(
                'Duplicate bank account',
                'tech@so-sure.com',
                sprintf('Check %s / %s', $policy->getPolicyNumber(), $policy->getId())
            );
        }

        $this->dispatcher->dispatch(PolicyEvent::EVENT_BACS_CREATED, new PolicyEvent($policy));
    }
}
