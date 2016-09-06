<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;

class ObjectEvent extends Event
{
    const EVENT_VALIDATE = 'event.validate';

    protected $object;

    public function __construct($object)
    {
        $this->object = $object;
    }

    public function getObject()
    {
        return $this->object;
    }
}
