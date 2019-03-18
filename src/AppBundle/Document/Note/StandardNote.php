<?php

namespace AppBundle\Document\Note;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
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
    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $action;

    public function setAction($action)
    {
        $this->action = $action;
    }

    public function getAction()
    {
        return $this->action;
    }
}
