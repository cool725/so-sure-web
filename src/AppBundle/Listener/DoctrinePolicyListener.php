<?php

namespace AppBundle\Listener;

use AppBundle\Annotation\DataChange;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\CardEvent;
use AppBundle\Event\UserEvent;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Policy;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrinePolicyListener extends BaseDoctrineListener
{
    use CurrencyTrait;

    /** @var EventDispatcherInterface */
    protected $dispatcher;
    /** @var string */
    protected $environment;

    public function __construct($dispatcher, $environment)
    {
        $this->dispatcher = $dispatcher;
        $this->environment = $environment;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        /** @var Policy $policy */
        $policy = $eventArgs->getDocument();
        if ($policy instanceof Policy) {
            if (!$policy->isValidPolicy(mb_strtoupper($this->environment))) {
                return;
            }
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['potValue', 'promoPotValue']
        )) {
            $this->triggerEvent($policy, PolicyEvent::EVENT_UPDATED_POT);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['premium'],
            DataChange::COMPARE_OBJECT_EQUALS
        )) {
            $this->triggerEvent($policy, PolicyEvent::EVENT_UPDATED_PREMIUM);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['billing'],
            DataChange::COMPARE_OBJECT_EQUALS
        )) {
            $this->triggerEvent($policy, PolicyEvent::EVENT_UPDATED_BILLING);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['status'],
            DataChange::COMPARE_EQUAL,
            true
        )) {
            $this->triggerEvent($policy, PolicyEvent::EVENT_UPDATED_STATUS, $eventArgs->getOldValue('status'));
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['paymentMethod'],
            DataChange::COMPARE_BACS
        )) {
            /** @var BacsPaymentMethod $paymentMethod */
            $paymentMethod = $policy->getPaymentMethod();

            // prefer the old bank account data if it exists
            /** @var BacsPaymentMethod $oldValue */
            $oldValue = $eventArgs->getOldValue('paymentMethod');
            if ($oldValue instanceof BacsPaymentMethod && $oldValue->getBankAccount()) {
                /** @var BacsPaymentMethod $paymentMethod */
                $paymentMethod = $oldValue;
            }

            $bankAccount = clone $paymentMethod->getBankAccount();
            $event = new BacsEvent($bankAccount);
            $event->setPolicy($policy);
            $this->dispatcher->dispatch(BacsEvent::EVENT_UPDATED, $event);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['paymentMethod'],
            DataChange::COMPARE_JUDO
        )) {
            $event = new CardEvent();
            $event->setPolicy($policy);
            $this->dispatcher->dispatch(CardEvent::EVENT_UPDATED, $event);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['paymentMethod'],
            DataChange::COMPARE_PAYMENT_METHOD_CHANGED
        )) {
            $oldValue = $eventArgs->getOldValue('paymentMethod');

            $event = new PolicyEvent($policy);
            $event->setPreviousPaymentMethod($oldValue->getType());
            $this->dispatcher->dispatch(PolicyEvent::EVENT_PAYMENT_METHOD_CHANGED, $event);
        }

        if (
            $this->hasDataChangedByCategory($eventArgs, DataChange::CATEGORY_HUBSPOT, Policy::class) &&
            $policy->getHubspotId()
        ) {
            $this->triggerEvent($policy, PolicyEvent::EVENT_UPDATED_HUBSPOT);
        }
    }

    public function preRemove(LifecycleEventArgs $eventArgs)
    {
        /** @var Policy $document */
        $document = $eventArgs->getDocument();
        if ($document instanceof Policy) {
            if ($document->getStatus()) {
                throw new \Exception(sprintf('Unable to delete policy %s with status', $document->getId()));
            }
        }
    }

    private function triggerEvent(Policy $policy, $eventType, $previousStatus = null)
    {
        $event = new PolicyEvent($policy);
        if ($previousStatus) {
            $event->setPreviousStatus($previousStatus);
        }
        $this->dispatcher->dispatch($eventType, $event);
    }
}
