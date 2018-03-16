<?php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * make sure to update getType() if adding
 * @MongoDB\DiscriminatorMap({"judo"="JudoPaymentMethod","gocardless"="GocardlessPaymentMethod",
 *      "bacs"="BacsPaymentMethod"})
 * @Gedmo\Loggable
 */
abstract class PaymentMethod
{
    abstract public function isValid();

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
            return 'judo';
        } elseif ($this instanceof GocardlessPaymentMethod) {
            return 'gocardless';
        } elseif ($this instanceof BacsPaymentMethod) {
            return 'bacs';
        } else {
            return null;
        }
    }
}
