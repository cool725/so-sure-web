<?php
// src/AppBundle/Document/User.php

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
}
