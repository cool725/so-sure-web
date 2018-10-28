<?php

namespace AppBundle\Listener;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Event\PaymentEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Payment\DebtCollectionPayment;

class DebtCollectionListener
{
    use CurrencyTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        if ($event->getPayment()->getPolicy()->getStatus() == Policy::STATUS_CANCELLED &&
            $event->getPayment()->getPolicy()->getDebtCollector() === Policy::DEBT_COLLECTOR_WISE) {
            $amount = $this->toTwoDp($event->getPayment()->getAmount() * 0.15);
            if ($amount < 20) {
                $amount = 20;
            }
            $amount = 0 - $amount;

            $debtCollection = new DebtCollectionPayment();
            $debtCollection->setAmount($amount);
            $debtCollection->setDate(\DateTime::createFromFormat('U', time()));
            $debtCollection->setNotes(sprintf(
                'Debt collection fee from %s',
                $event->getPayment()->getPolicy()->getDebtCollector()
            ));
            $event->getPayment()->getPolicy()->addPayment($debtCollection);
            $this->dm->flush();
        }
    }
}
