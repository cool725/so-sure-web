<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Company;
use AppBundle\Event\CompanyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineCompanyListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function postPersist(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Company) {
            $this->triggerEvent($document, CompanyEvent::EVENT_CREATED);
        }
    }

    private function triggerEvent(Company $company, $eventType)
    {
        $event = new CompanyEvent($company);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
