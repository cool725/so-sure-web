<?php

namespace AppBundle\Document\PaymentMethod;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * make sure to update getType() if adding
 * @MongoDB\DiscriminatorMap({"judo"="JudoPaymentMethod",
 *      "bacs"="BacsPaymentMethod", "checkout"="CheckoutPaymentMethod"})
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class PaymentMethod
{
    const TYPE_JUDO = 'judo';
    const TYPE_BACS = 'bacs';
    const TYPE_CHECKOUT = 'checkout';
    abstract public function isValid();
    abstract public function __toString();

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $firstProblem;

    public function getFirstProblem()
    {
        return $this->firstProblem;
    }

    public function setFirstProblem($firstProblem)
    {
        $this->firstProblem = $firstProblem;
    }

    public function getType()
    {
        if ($this instanceof JudoPaymentMethod) {
            return self::TYPE_JUDO;
        } elseif ($this instanceof BacsPaymentMethod) {
            return self::TYPE_BACS;
        } elseif ($this instanceof CheckoutPaymentMethod) {
            return self::TYPE_CHECKOUT;
        } else {
            return null;
        }
    }
}
