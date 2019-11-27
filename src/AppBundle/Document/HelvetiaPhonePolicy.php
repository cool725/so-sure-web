<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Classes\Salva;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class HelvetiaPhonePolicy extends PhonePolicy
{
    /**
     * @inheritDoc
     */
    public function getUnderwriterTimeZone()
    {
        return new \DateTimeZone("Europe/Zurich");
    }

    /**
     * @inheritDoc
     */
    public function getUnderwriterName()
    {
        return "Helvetia";
    }

    /**
     * @inheritDoc
     */
    public function setTotalCommission(Payment $payment)
    {
        // TODO: this.

    }
}
