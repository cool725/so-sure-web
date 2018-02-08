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
 * @MongoDB\DiscriminatorMap({"judo"="JudoPaymentMethod","gocardless"="GocardlessPaymentMethod"})
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
}
