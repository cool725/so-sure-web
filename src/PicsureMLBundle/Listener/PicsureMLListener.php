<?php

namespace PicsureMLBundle\Listener;

use AppBundle\Document\File\S3File;
use PicsureMLBundle\Event\PicsureMLEvent;
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
     * @param PicsureMLEvent $event
     */
    public function onUndamagedEvent(PicsureMLEvent $event)
    {
        $this->picsureMLService->addFileForTraining($event->getS3File(), TrainingData::LABEL_UNDAMAGED);
    }

    /**
     * @param PicsureMLEvent $event
     */
    public function onInvalidEvent(PicsureMLEvent $event)
    {
        $this->picsureMLService->addFileForTraining($event->getS3File(), TrainingData::LABEL_INVALID);
    }

    /**
     * @param PicsureMLEvent $event
     */
    public function onDamagedEvent(PicsureMLEvent $event)
    {
        $this->picsureMLService->addFileForTraining($event->getS3File(), TrainingData::LABEL_DAMAGED);
    }
}
