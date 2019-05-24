<?php
namespace AppBundle\Service;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\File\DirectDebitNotificationFile;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
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

    /** @var CheckoutService $checkout */
    protected $checkout;

    /** @var RouterService */
    protected $routerService;

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
     * @param CheckoutService          $checkout
     * @param RouterService            $routerService
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
        CheckoutService $checkout,
        RouterService $routerService,
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
        $this->checkout = $checkout;
        $this->routerService = $routerService;
        $this->logger = $logger;
        $this->dm = $dm;
        $this->mailer = $mailer;
        $this->sequenceService = $sequenceService;
        $this->environment = $environment;
        $this->fraudService = $fraudService;
        $this->templating = $templating;
        $this->dispatcher = $dispatcher;
    }

    public function setMailerMailer($mailer)
    {
        $this->mailer->setMailer($mailer);
    }

    public function getAllValidScheduledPaymentsForType(
        $prefix,
        $type,
        \DateTime $scheduledDate = null,
        $validateBillable = true
    ) {
        return $this->getAllValidScheduledPaymentsForTypes($prefix, [$type], $scheduledDate, $validateBillable);
    }

    public function getAllValidScheduledPaymentsForTypes(
        $prefix,
        $types,
        \DateTime $scheduledDate = null,
        $validateBillable = true
    ) {
        $results = [];

        /** @var ScheduledPaymentRepository $repo */
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $repo->findScheduled($scheduledDate);
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */

            $foundType = false;
            foreach ($types as $type) {
                if ($scheduledPayment->getPolicy()->getPaymentMethod() instanceof $type) {
                    $foundType = true;
                }
            }
            if (!$foundType) {
                continue;
            }

            if ($validateBillable && !$scheduledPayment->isBillable()) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->isValidPolicy($prefix)) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->hasPolicyOrPayerOrUserValidPaymentMethod()) {
                $this->logger->info(sprintf(
                    'Policy %s or User %s does not have a valid payment method',
                    $scheduledPayment->getPolicy()->getId(),
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
        $paymentMethod = $policy->getPaymentMethod();
        if ($paymentMethod && $paymentMethod instanceof JudoPaymentMethod) {
            $this->logger->alert(sprintf(
                "Scheduled payment for Judo! For policy %s",
                $policy->getId()
            ));
            throw new \Exception(sprintf(
                'JudoPay payment method not valid for scheduled payment %s',
                $scheduledPayment->getId()
            ));
        } elseif ($paymentMethod && $paymentMethod instanceof CheckoutPaymentMethod) {
                return $this->checkout->scheduledPayment(
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
     * @param BankAccount $bankAccount
     * @param User        $user
     * @return string
     * @throws \Exception
     */
    public function generateBacsReference(BankAccount $bankAccount, User $user)
    {
        if ($this->environment == 'prod') {
            $seq = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_REFERENCE);
        } else {
            $seq = $this->sequenceService->getSequenceId(SequenceService::SEQUENCE_BACS_REFERENCE_INVALID);
        }
        $ref = $bankAccount->generateReference($user, $seq);

        return $ref;
    }

    /**
     * @param Policy            $policy
     * @param BacsPaymentMethod $bacsPaymentMethod
     */
    public function confirmBacs(Policy $policy, BacsPaymentMethod $bacsPaymentMethod, \DateTime $date = null)
    {
        $policy->setPaymentMethod($bacsPaymentMethod);
        $bacsPaymentMethod->getBankAccount()->setInitialNotificationDate(
            $bacsPaymentMethod->getBankAccount()->getFirstPaymentDateForPolicy($policy, $date)
        );
        $bacsPaymentMethod->getBankAccount()->setFirstPayment(true);
        $bacsPaymentMethod->getBankAccount()->setStandardNotificationDate($policy->getBilling());
        // ensure payer is current user for bacs
        if ($policy->isDifferentPayer()) {
            $policy->setPayer($policy->getUser());
        }
        $policy->setPolicyStatusActiveIfUnpaid();

        if ($this->environment == 'prod' && !$policy->isValidPolicy()) {
            $bacsPaymentMethod->getBankAccount()->setMandateStatus(BankAccount::MANDATE_FAILURE);
        }

        $this->dm->flush();

        $this->mailer->sendTemplateToUser(
            sprintf('Your Direct Debit Confirmation'),
            $policy->getUser(),
            'AppBundle:Email:bacs/notification.html.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            'AppBundle:Email:bacs/notification.txt.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            null,
            'bcc-ddnotifications@so-sure.com'
        );

        if (count($this->fraudService->getDuplicatePolicyBankAccounts($policy)) > 0) {
            $this->mailer->send(
                'Duplicate bank account',
                'tech@so-sure.com',
                sprintf(
                    'Check <a href="%s">duplicate bank account</a>, Policy Id: %s',
                    $this->routerService->generateUrl('admin_policy', ['id' => $policy->getId()]),
                    $policy->getId()
                )
            );
        }

        $this->dispatcher->dispatch(PolicyEvent::EVENT_BACS_CREATED, new PolicyEvent($policy));
    }
}
