<?php

namespace AppBundle\Document\Excess;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({
 *      "phone"="PhoneExcess",
 * })
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Excess
{
    abstract public function getValue($type);

    abstract public function toApiArray();
}
