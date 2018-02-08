<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\File\S3File;
use AppBundle\Event\PicsureEvent;

class PicsureListener
{
    const PICSURE_UNDAMAGED = 'undamaged';
    const PICSURE_INVALID = 'invalid';
    const PICSURE_DAMAGED = 'damaged';

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(
        DocumentManager $dm
    ) {
        $this->dm = $dm;
    }

    /**
     * @param PicsureEvent $event
     */
    public function onUndamagedEvent(PicsureEvent $event)
    {
        $file = $event->getS3File();
        $this->updateS3File($file, self::PICSURE_UNDAMAGED);
    }

    /**
     * @param PicsureEvent $event
     */
    public function onInvalidEvent(PicsureEvent $event)
    {
        $file = $event->getS3File();
        $this->updateS3File($file, self::PICSURE_INVALID);
    }

    /**
     * @param PicsureEvent $event
     */
    public function onDamagedEvent(PicsureEvent $event)
    {
        $file = $event->getS3File();
        $this->updateS3File($file, self::PICSURE_DAMAGED);     
    }

    private function updateS3File(S3File $file, $status)
    {
        $file->addMetadata('status', $status);
        $this->dm->flush();
    }
}
