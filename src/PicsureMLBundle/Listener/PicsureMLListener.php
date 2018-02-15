<?php

namespace PicsureMLBundle\Listener;

use AppBundle\Document\File\S3File;
use AppBundle\Event\PicsureEvent;
use PicsureMLBundle\Service\PicsureMLService;
use PicsureMLBundle\Document\TrainingData;

class PicsureMLListener
{
    /** @var PicsureMLService */
    protected $picsureMLService;

    /**
     * @param PicsureMLService $picsureMLService
     */
    public function __construct(PicsureMLService $picsureMLService)
    {
        $this->picsureMLService = $picsureMLService;
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
