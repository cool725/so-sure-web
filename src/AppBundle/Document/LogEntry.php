<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\LogEntryRepository")
 * @MongoDB\Index(keys={"objectId"="asc"}, sparse="true")
 * @MongoDB\Index(keys={"objectClass"="asc", "objectId"="asc"}, sparse="true")
 */
class LogEntry extends \Gedmo\Loggable\Document\LogEntry
{
    /**
     * Sets the logged at date to a specific date since the normal setLoggedAt function just sets it to be the current
     * system time which is not useful for testing it.
     * @param \DateTime $date we want it to say it was logged at.
     */
    public function setLoggedAtSpecifically($date)
    {
        $this->loggedAt = $date;
    }
}
