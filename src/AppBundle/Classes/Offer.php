<?php

namespace AppBundle\Classes;

use AppBundle\Document\User;
use AppBundle\Document\Phone;

/**
 * Represents an offering of a price to a user that can be persisted into redis for a time.
 */
class Offering implements Serializable
{
    /**
     * id of the phone that the offer is about.
     * @var string
     */
    public $phoneId;

    /**
     * id of the offer that is being offered.
     * @var string
     */
    public $offerId;

    /**
     * @inheritDoc
     */
    public serialize()
    {
        return sprintf("%s:%s", $this->phoneId, $this->offerId);
    }

    /**
     * @inheritDoc
     */
    public unserialize($serialized)
    {
        $items = explode

    }
}
