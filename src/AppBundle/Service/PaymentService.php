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
use AppBundle\Exception\InvalidPaymentMethodException;
use AppBundle\Exception\SameDayPaymentException;
use AppBundle\Exception\ScheduledPaymentException;
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
        $type,
        \DateTime $scheduledDate = null,
        $validateBillable = true,
        $policyType = null,
        $limit = -1
    ) {
        return $this->getAllValidScheduledPaymentsForTypes(
            [$type],
            $scheduledDate,
            $validateBillable,
            $policyType,
            $limit
        );
    }

    public function getAllValidScheduledPaymentsForTypes(
        $types,
        \DateTime $scheduledDate = null,
        $validateBillable = true,
        $policyType = null,
        $limit = -1
    ) {
        $results = [];

        /** @var ScheduledPaymentRepository $repo */
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $repo->findScheduled($scheduledDate, $policyType, $limit);
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
            if (!$scheduledPayment->getPolicy()->isValidPolicy()) {
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

    /**
     * Finds all scheduled bacs payments up to the given date and can check if they are billable or not.
     * @param \DateTime|null $scheduledDate    is the date up to which the payments can be for.
     * @param boolean        $validateBillable is whether to exclude those that cannot be charged.
     * @param string|null    $policyType       is a policy type to limit the search to.
     * @param int            $limit            is a limit to put on the number of returned scheduled payments. If you
     *                                         give a value less than zero it doesn't use it.
     * @return array containing the selected scheduled payments.
     */
    public function getAllValidScheduledPaymentsForBacs(
        \DateTime $scheduledDate = null,
        $validateBillable = true,
        $policyType = null,
        $limit = -1
    ) {
        $results = [];
        /** @var ScheduledPaymentRepository $repo */
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        $this->labelBacs(null, $policyType);
        $scheduledPayments = $repo->findScheduledBacs($scheduledDate, $policyType, $limit);
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            if ($validateBillable && !$scheduledPayment->isBillable()) {
                continue;
            }
            if (!$scheduledPayment->getPolicy()->isValidPolicy()) {
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
        \DateTime $date = null,
        $abortOnMultipleSameDayPayment = true
    ) {
        try {
            $scheduledPayment->validateRunable($date);
        } catch (ScheduledPaymentException $e) {
            /**
             * This should never be thrown as the only place that calls this that is not
             * a test file has the same check before it calls this method.
             * Nonetheless I have checked for the exception here because it would be amiss
             * not to be conservative in my code so that even code that shouldn't ever be
             * hit is complete, on the off chance that it could be hit.
             * For now we will rethrow the exception too so that the calling method can
             * decide what to do with the exception.
             */
            $this->logger->error($e->getMessage());
            throw $e;
        }

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
            /**
             * We could let the Exceptions bubble up here, but seeing as
             * we are already adding in a load of try catches, we may as
             * well be thorough about it and catch and log them here
             * just in case they have not been logged elsewhere.
             */
            try {
                $payment = $this->checkout->scheduledPayment(
                    $scheduledPayment,
                    $date,
                    $abortOnMultipleSameDayPayment
                );
            } catch (ScheduledPaymentException $e) {
                $this->logger->error($e->getMessage());
                throw $e;
            } catch (SameDayPaymentException $e) {
                $this->logger->error($e->getMessage());
                throw $e;
            } catch (InvalidPaymentMethodException $e) {
                $this->logger->error($e->getMessage());
                throw $e;
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                throw $e;
            }
            return $payment;
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
        if ($policy->isPolicyPaidToDate(null, true)) {
            $policy->setPolicyStatusActiveIfUnpaid();
        }

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

    /**
     * Sets the field paymentType to bacs for all scheduled payments that reference a bacs payment.
     * @param \DateTime|null $date       is the date up to which to label scheduled payments.
     * @param string|null    $policyType is a type of policy to look for exclusively. If you give null it ignores.
     */
    private function labelBacs($date = null, $policyType = null)
    {
        $date = $date ?: new \DateTime();
        // Delete existing paymentType field.
        $this->dm->createQueryBuilder(ScheduledPayment::class)
            ->updateMany()
            ->field('paymentType')->unsetField()->exists(true)
            ->getQuery()
            ->execute();
        // Finds those with bacs policies.
        $scheduledIds = [];
        $scheduledsQuery = $this->dm->createQueryBuilder(ScheduledPayment::class)
            ->hydrate(false)
            ->field('status')->equals(ScheduledPayment::STATUS_SCHEDULED)
            ->field('scheduled')->lt($date);
        if ($policyType) {
            $scheduledsQuery->field('policy.policy_type')->equals($policyType);
        }
        $scheduleds = $scheduledsQuery->getQuery()->execute();
        foreach ($scheduleds as $scheduled) {
            $policy = $this->dm->createQueryBuilder(Policy::class)
                ->hydrate(false)
                ->field('_id')->equals($scheduled['policy']['$id'])
                ->getQuery()
                ->getSingleResult();
            if ($policy) {
                $scheduledIds[] = $scheduled['_id'];
            }
        }
        // Sets paymentType: bacs for those found.
        $this->dm->createQueryBuilder(ScheduledPayment::class)
            ->updateMany()
            ->field('_id')->in($scheduledIds)
            ->field('paymentType')->set('bacs')
            ->getQuery()
            ->execute();
        $this->dm->flush();
    }
}
