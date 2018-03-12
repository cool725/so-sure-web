<?php
namespace AppBundle\Service;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Psr\Log\LoggerInterface;

class ScheduledPaymentService
{
    /** @var JudopayService $judopay */
    protected $judopay;

    /** @var LoggerInterface $logger */
    protected $logger;

    /**
     * @param JudopayService  $judopay
     * @param LoggerInterface $logger
     */
    public function __construct(
        JudopayService $judopay,
        LoggerInterface $logger
    ) {
        $this->judopay = $judopay;
        $this->logger = $logger;
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
}
