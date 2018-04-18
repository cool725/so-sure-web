<?php

namespace AppBundle\Listener;

use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Policy;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrinePolicyListener
{
    use CurrencyTrait;

    /** @var EventDispatcher */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $dispatched = [];
        $document = $eventArgs->getDocument();
        if ($document instanceof Policy) {
            if (!$document->isValidPolicy()) {
                return;
            }

            $fields = [
                'potValue',
                'promoPotValue',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field)) {
                    if (!in_array($document->getId(), $dispatched)) {
                        $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_POT);
                        $dispatched[] = $document->getId();
                    }
                }
            }

            if ($eventArgs->hasChangedField('premium')) {
                    $oldPremium = $eventArgs->getOldValue('premium');
                    $newPremium = $eventArgs->getNewValue('premium');
                    if ((!$oldPremium && $newPremium)
                        || !$this->areEqualToTwoDp($oldPremium->getGwp(), $newPremium->getGwp())
                        || !$this->areEqualToTwoDp($oldPremium->getIpt(), $newPremium->getIpt())
                        || !$this->areEqualToTwoDp($oldPremium->getIptRate(), $newPremium->getIptRate())) {
                        $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_PREMIUM);
                    }
            }

            if ($eventArgs->hasChangedField('billing')) {
                $oldBilling = $eventArgs->getOldValue('billing');
                $newBilling = $eventArgs->getNewValue('billing');
                if ($oldBilling != $newBilling) {
                    $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_BILLING);
                }
            }
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

    private function triggerEvent(Policy $policy, $eventType)
    {
        $event = new PolicyEvent($policy);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
