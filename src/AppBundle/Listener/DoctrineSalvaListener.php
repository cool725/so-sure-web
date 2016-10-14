<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineSalvaListener
{
    /** @var EventDispatcher */
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
        $document = $eventArgs->getDocument();
        if ($document instanceof SalvaPhonePolicy) {
            if (!$document->isValidPolicy()) {
                return;
            }

            $fields = [
                'phone.make',
                'phone.model',
                'phone.memory',
                'phone.imei',
                'phone.initialPrice',
                'premium.gwp',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field)) {
                    return $this->triggerEvent($document, PolicyEvent::EVENT_SALVA_INCREMENT);
                }
            }
        }

        if ($document instanceof User) {
            if (!$document->hasValidPolicy()) {
                return;
            }
            $fields = [
                'firstName',
                'lastName',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field)) {
                    foreach ($document->getPolicies() as $policy) {
                        if ($policy instanceof SalvaPhonePolicy && $policy->isValidPolicy()) {
                            return $this->triggerEvent($policy, PolicyEvent::EVENT_SALVA_INCREMENT);
                        }
                    }
                }
            }
        }
    }

    private function triggerEvent(SalvaPhonePolicy $phonePolicy, $eventType)
    {
        $event = new PolicyEvent($phonePolicy);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
