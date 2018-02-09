<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\File\S3File;
use AppBundle\Event\PicsureEvent;
use PicsureMLBundle\Service\PicsureMLService;

class PicsureListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var PicsureMLService */
    protected $picsureMLService;

    /**
     * @param DocumentManager  $dm
     * @param PicsureMLService $picsureMLService
     */
    public function __construct(
        DocumentManager $dm,
        PicsureMLService $picsureMLService
    ) {
        $this->dm = $dm;
        $this->picsureMLService = $picsureMLService;
    }

    /**
     * @param PicsureEvent $event
     */
    public function onUndamagedEvent(PicsureEvent $event)
    {
        $file = $event->getS3File();
        $this->updateS3File($file, PhonePolicy::PICSURE_STATUS_APPROVED);
    }

    /**
     * @param PicsureEvent $event
     */
    public function onInvalidEvent(PicsureEvent $event)
    {
        $file = $event->getS3File();
        $this->updateS3File($file, PhonePolicy::PICSURE_STATUS_INVALID);
    }

    /**
     * @param PicsureEvent $event
     */
    public function onDamagedEvent(PicsureEvent $event)
    {
        $file = $event->getS3File();
        $this->updateS3File($file, PhonePolicy::PICSURE_STATUS_INVALID);
    }

    private function updateS3File(S3File $file, $status)
    {
        $file->addMetadata('status', $status);
        $this->dm->flush();

        $this->picsureMLService->addFileForTraining($file, $status);
    }
}
