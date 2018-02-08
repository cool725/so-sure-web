<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\File\S3File;

class PicsureEvent extends Event
{
    const EVENT_UNDAMAGED = 'event.picsure.undamaged';
    const EVENT_INVALID = 'event.picsure.invalid';
    const EVENT_DAMAGED = 'event.picsure.damaged';

    /** @var S3File */
    protected $s3File;

    public function __construct($s3File)
    {
        $this->s3File = $s3File;
    }

    public function getS3File()
    {
        return $this->s3File;
    }
}
