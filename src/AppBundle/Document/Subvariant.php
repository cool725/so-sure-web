<?php

namespace AppBundle\Document;

use InvalidArgumentException;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Represents a type of policy that can be sold that only allows certain claim types.
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\SubvariantRepository")
 */
class Subvariant
{
    const VARIANT_TYPES = [
      "Essential" => "essentials",
      "Damage" => "damage"
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    private $id;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true)
     */
    private $name;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    private $loss;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    private $theft;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    private $damage;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    private $warranty;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    private $extendedWarranty;

    /**
     * The number of claims that people on this policy can have.
     * @Assert\Range(min=1,max=1000)
     * @MongoDB\Field(type="int")
     */
    private $nClaims;

    /**
     * Prefix to give to policies with this subvariant.
     * @MongoDB\Field(type="string")
     */
    private $policyPrefix;

    /**
     * Gives you the subvariant's mongo id.
     * @return string the monbgo id as a string.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Gives you the subvariant's name. This name is only meant to be shown to admin users to distinguish it
     * from other variants.
     * @return string the name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets the subvariant's name.
     * @param string $name is the name to identify the subvariant with to admins.
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Gives you whether this subvariant of policies allows loss claims.
     * @return boolean whether or not the subvariant allows loss claims.
     */
    public function getLoss()
    {
        return $this->loss;
    }

    /**
     * Lets you set whether or not this subvariant allows loss claims.
     * @param boolean $loss is whether or not to allow them.
     */
    public function setLoss($loss)
    {
        $this->loss = $loss;
    }

    /**
     * Tells you whether or not this subvariant allows theft claims.
     * @return boolean whether or not theft claims are allowed.
     */
    public function getTheft()
    {
        return $this->theft;
    }

    /**
     * Sets whether or not this subvariant allows theft claims.
     * @param boolean $theft is whether or not to allow them.
     */
    public function setTheft($theft)
    {
        $this->theft = $theft;
    }

    /**
     * Tells you whether or not this subvariant allows damage claims.
     * @return boolean whether or not they are allowed.
     */
    public function getDamage()
    {
        return $this->damage;
    }

    /**
     * Sets whether or not this subvariant allows damage claims.
     * @param boolean $damage is whether or not damage claims are allowed.
     */
    public function setDamage($damage)
    {
        $this->damage = $damage;
    }

    /**
     * Tells you whether or not warranty claims are allowed for this subvariant.
     * @return boolean whether or not they are allowed.
     */
    public function getWarranty()
    {
        return $this->warranty;
    }

    /**
     * Sets whether or not warranty claims are allowed for this subvariant.
     * @param boolean $warranty is whether or not warranty claims shall be allowed.
     */
    public function setWarranty($warranty)
    {
        $this->warranty = $warranty;
    }

    /**
     * Tells whether or not extended warranty claims are allowed.
     * @return boolean whether or not they are allowed.
     */
    public function getExtendedWarranty()
    {
        return $this->extendedWarranty;
    }

    /**
     * Sets whether or not extended warranty claims are allowed.
     * @param boolean $extendedWarranty is whether or not they are allowed.
     */
    public function setExtendedWarranty($extendedWarranty)
    {
        $this->extendedWarranty = $extendedWarranty;
    }

    /**
     * Tells you the number of claims that this subvariant allows.
     * @return int the number of claims allowed.
     */
    public function getNClaims()
    {
        return $this->nClaims;
    }

    /**
     * Sets the number of claims that this subvariant allows.
     * @param int $nClaims is the number of claims to allow.
     */
    public function setNClaims($nClaims)
    {
        $this->nClaims = $nClaims;
    }

    /**
     * Gives you the prefix that policies with this subvariant should have.
     * @return string the prefix.
     */
    public function getPolicyPrefix()
    {
        return $this->policyPrefix;
    }

    /**
     * Sets the prefix that policies under this subvariant should have.
     * @param string $policyPrefix is the prefix to give them.
     */
    public function setPolicyPrefix($policyPrefix)
    {
        $this->policyPrefix = $policyPrefix;
    }

    /**
     * Tells you if this subvariant allows a given type of claim.
     * @param string $claimType is the type of claim to check on.
     * @param Policy $policy    is the policy doing the claim.
     * @return boolean true iff it is indeed allowed.
     */
    public function allows($claimType, $policy)
    {
        $n = count($policy->getApprovedClaims(true));
        if ($n >= $this->getNClaims()) {
            return false;
        }
        switch ($claimType) {
            case Claim::TYPE_LOSS:
                return $this->getLoss();
            case Claim::TYPE_THEFT:
                return $this->getTheft();
            case Claim::TYPE_DAMAGE:
                return $this->getDamage();
            case Claim::TYPE_WARRANTY:
                return $this->getWarranty();
            case Claim::TYPE_EXTENDED_WARRANTY:
                return $this->getExtendedWarranty();
            default:
                throw new InvalidArgumentException("'{$claimType}' is not a claim type");
        }
    }
}
