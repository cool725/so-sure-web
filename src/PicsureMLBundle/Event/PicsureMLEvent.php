<?php

namespace PicsureMLBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\File\S3File;

class PicsureMLEvent extends Event
{
    const EVENT_UNDAMAGED = 'event.picsureml.undamaged';
    const EVENT_INVALID = 'event.picsureml.invalid';
    const EVENT_DAMAGED = 'event.picsureml.damaged';

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
