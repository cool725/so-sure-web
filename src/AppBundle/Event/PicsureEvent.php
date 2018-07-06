<?php

namespace AppBundle\Event;

use AppBundle\Document\Policy;
use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\File\S3File;

class PicsureEvent extends Event
{
    const EVENT_RECEIVED = 'event.picsure.received';
    const EVENT_APPROVED = 'event.picsure.approved';
    const EVENT_INVALID = 'event.picsure.invalid';
    const EVENT_REJECTED = 'event.picsure.rejected';

    /** @var S3File */
    protected $s3File;

    /** @var Policy */
    protected $policy;

    public function __construct(Policy $policy, S3File $s3File)
    {
        $this->policy = $policy;
        $this->s3File = $s3File;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function getS3File()
    {
        return $this->s3File;
    }
}
