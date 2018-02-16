<?php

namespace PicsureMLBundle\Listener;

use Psr\Log\LoggerInterface;
use AppBundle\Document\File\S3File;
use AppBundle\Event\PicsureEvent;
use PicsureMLBundle\Service\PicsureMLService;
use PicsureMLBundle\Document\TrainingData;

class PicsureMLListener
{
    /** @var PicsureMLService */
    protected $picsureMLService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param PicsureMLService $picsureMLService
     * @param LoggerInterface  $logger
     */
    public function __construct(PicsureMLService $picsureMLService, LoggerInterface $logger)
    {
        $this->picsureMLService = $picsureMLService;
        $this->logger = $logger;
    }

    /**
     * @param PicsureEvent $event
     */
    public function onReceivedEvent(PicsureEvent $event)
    {
        try {
            $this->picsureMLService->predict($event->getS3File());
        } catch (\Exception $e) {
            $this->logger->warning($e->getMessage());
        }
    }

    /**
     * @param PicsureEvent $event
     */
    public function onUndamagedEvent(PicsureEvent $event)
    {
        $this->picsureMLService->addFileForTraining($event->getS3File(), TrainingData::LABEL_UNDAMAGED);
    }

    /**
     * @param PicsureEvent $event
     */
    public function onInvalidEvent(PicsureEvent $event)
    {
        $this->picsureMLService->addFileForTraining($event->getS3File(), TrainingData::LABEL_INVALID);
    }

    /**
     * @param PicsureEvent $event
     */
    public function onDamagedEvent(PicsureEvent $event)
    {
        $this->picsureMLService->addFileForTraining($event->getS3File(), TrainingData::LABEL_DAMAGED);
    }
}
