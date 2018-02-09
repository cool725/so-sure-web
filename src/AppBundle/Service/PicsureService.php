<?php

namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PicsureEvent;

class PicsureService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $dispatcher;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param Dispatcher      $dispatcher
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger, $dispatcher)
    {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
    }

    public function setFileUndamaged($file)
    {
        $this->dispatchEvent(PicsureEvent::EVENT_UNDAMAGED, new PicsureEvent($file));
    }

    public function setFileInvalid($file)
    {
        $this->dispatchEvent(PicsureEvent::EVENT_INVALID, new PicsureEvent($file));
    }

    public function setFileDamaged($file)
    {
        $this->dispatchEvent(PicsureEvent::EVENT_DAMAGED, new PicsureEvent($file));
    }

    private function dispatchEvent($eventType, $event)
    {
        if ($this->dispatcher) {
            $this->dispatcher->dispatch($eventType, $event);
        } else {
            $this->logger->warning('Dispatcher is disabled for Picsure Service');
        }
    }
}
