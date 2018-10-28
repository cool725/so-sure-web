<?php

namespace AppBundle\Listener;

use AppBundle\Annotation\DataChange;
use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Claim;
use AppBundle\Event\ClaimEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrineClaimListener extends BaseDoctrineListener
{
    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function postPersist(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Claim) {
            $this->triggerEvent($document, ClaimEvent::EVENT_CREATED);

            if ($eventType = $this->getEventType($document->getStatus())) {
                $this->triggerEvent($document, $eventType);
            }
        }
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        /** @var Claim $claim */
        $claim = $eventArgs->getDocument();
        if ($this->hasDataChanged($eventArgs, Claim::class, ['status'])) {
            if ($eventType = $this->getEventType($eventArgs->getNewValue('status'))) {
                $this->triggerEvent($claim, $eventType);
            }
            $this->triggerEvent($claim, $eventArgs->getNewValue('status'));
        }

        if ($this->hasDataChangedByCategory($eventArgs, DataChange::CATEGORY_SALVA_CLAIM)) {
            $claim->setUnderwriterLastUpdated(\DateTime::createFromFormat('U', time()));
            $this->recalulateChangeSet($eventArgs, $claim);
        }
    }

    private function getEventType($status)
    {
        if ($status == Claim::STATUS_APPROVED) {
            return ClaimEvent::EVENT_APPROVED;
        } elseif ($status == Claim::STATUS_SETTLED) {
            return ClaimEvent::EVENT_SETTLED;
        }

        return null;
    }

    private function triggerEvent(Claim $claim, $eventType)
    {
        $event = new ClaimEvent($claim);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
