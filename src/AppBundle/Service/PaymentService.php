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
use AppBundle\Classes\Salva;
use AppBundle\Classes\Helvetia;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Exception\InvalidPaymentMethodException;
use AppBundle\Exception\SameDayPaymentException;
use AppBundle\Exception\ScheduledPaymentException;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\PolicyRepository;
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
        $policyType = null,
        $limit = -1,
        $polarity = 0
    ) {
        $scheduledDate = $scheduledDate ?: new \DateTime();
        $label = $this->labelType($type, $scheduledDate, $policyType, $polarity);
        $query = $this->dm->createQueryBuilder(ScheduledPayment::class)->field('labels')->equals($label);
        if ($limit > 0) {
            $query->limit($limit);
        }
        return $query->getQuery()->execute();
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
     * Finds all matching scheduled payments that reference a valid policy of the given type and sets a unique random
     * flag on them.
     * @param string         $paymentMethod type of payment method policies should have (or null for all).
     * @param \DateTime|null $before        only payments before the given date will be found and if it is null then
     *                                      it's now.
     * @param string|null    $policyType    only find payments referencing policies of the given type.
     * @param int            $polarity      determines whether to affect positive, negative, or all payments with 0.
     * @return string the label with which to find the labelled scheduled payments.
     */
    private function labelType($paymentMethod, $before = null, $policyType = null, $polarity = 0)
    {
        $before = $before ?: new \DateTime();
        $label = uniqid();
        $policyQuery = $this->dm->createQueryBuilder(Policy::class)
            ->hydrate(false)
            ->field('policyNumber')->equals(new \MongoRegex(PolicyRepository::VALID_REGEX))
            ->field('paymentMethod.type')->equals($paymentMethod);
        if ($policyType !== null) {
            $policyQuery->field('policy_type')->equals($policyType);
        }
        $policies = $policyQuery->getQuery()->execute();
        $ids = [];
        $i = 0;
        foreach ($policies as $policy) {
            $ids[] = $policy['_id'];
            $i++;
            if ($i == 10000) {
                $ids = [];
                $i = 0;
                $this->labelAll($ids, $label, $before, $polarity);
            }
        }
        if ($i > 0) {
            $this->labelAll($ids, $label, $before, $polarity);
        }
        return $label;
    }

    /**
     * Removes the given label from all scheduled payments.
     * @param string $label is the label to remove.
     */
    private function removeLabel($label)
    {
        $this->dm->createQueryBuilder(ScheduledPayment::class)
            ->hydrate(false)
            ->field('labels')->pull($label)
            ->getQuery()->execute();
    }

    /**
     * Labels all scheduled payments belonging to the given payments as long as they are in status scheduled, before
     * the given date and in accordance with the given polarity.
     * @param array     $ids      is the list of policy ids.
     * @param string    $label    is the label to give to them.
     * @param \DateTime $before   is the date before to find them.
     * @param int       $polarity means negative positive or both.
     */
    private function labelAll($ids, $label, $before, $polarity)
    {
        $query = $this->dm->createQueryBuilder(ScheduledPayment::class)
            ->hydrate(false)
            ->updateMany()
            ->field('policy.$id')->in($ids)
            ->field('scheduled')->lte($before)
            ->field('status')->equals(ScheduledPayment::STATUS_SCHEDULED)
            ->field('labels')->push($label);
        if ($polarity < 0) {
            $query->field('amount')->lt(0);
        }
        if ($polarity > 0) {
            $query->field('amount')->gt(0);
        }
        $query->getQuery()->execute();
    }
}
