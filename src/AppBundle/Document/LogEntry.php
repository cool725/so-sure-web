<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document()
 * @MongoDB\Index(keys={"objectId"="asc"}, unique="false", sparse="true")
 */
class LogEntry extends \Gedmo\Loggable\Document\LogEntry
{
}
