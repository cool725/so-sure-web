<?php
namespace AppBundle\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\UserBundle\Mailer\Mailer;
use Psr\Log\LoggerInterface;

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
    protected  $sequenceService;

    /** @var string */
    protected $environment;

    /**
     * PaymentService constructor.
     * @param JudopayService  $judopay
     * @param LoggerInterface $logger
     * @param DocumentManager $dm
     * @param SequenceService $sequenceService
     * @param MailerService   $mailer
     * @param string          $environment
     */
    public function __construct(
        JudopayService $judopay,
        LoggerInterface $logger,
        DocumentManager $dm,
        SequenceService $sequenceService,
        MailerService $mailer,
        $environment
    ) {
        $this->judopay = $judopay;
        $this->logger = $logger;
        $this->dm = $dm;
        $this->mailer = $mailer;
        $this->sequenceService = $sequenceService;
        $this->environment = $environment;
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
        $this->dm->flush();
        $this->mailer->sendTemplate(
            sprintf('Your Direct Debit Confirmation'),
            $policy->getUser()->getEmail(),
            'AppBundle:Email:bacs/notification.html.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            'AppBundle:Email:bacs/notification.txt.twig',
            ['user' => $policy->getUser(), 'policy' => $policy],
            null,
            'bcc@so-sure.com'
        );
    }
}
