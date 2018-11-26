<?php

namespace AppBundle\Document\Note;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument()
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class StandardNote extends Note
{

}
