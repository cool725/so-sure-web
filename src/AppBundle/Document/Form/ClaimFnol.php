<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * @AppAssert\PolicyClaimAllowed
 */
class ClaimFnol
{
    use DateTrait;

    /**
     * @var User
     */
    protected $user;

    /**
     * @Assert\Email(strict=false)
     */
    protected $email;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     */
    protected $name;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     */
    protected $signature;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     */
    protected $policyNumber;

    /**
     * @AppAssert\PhoneNumber(message="Please enter a valid phone number")
     */
    protected $phone;

    /**
     * @Assert\Choice({"loss", "theft", "damage", "warranty", "extended-warranty"}, strict=true)
     */
    protected $type;

    /**
     * @Assert\Choice(callback="getNetworks", strict=true)
     */
    protected $network;

    /**
     * @Assert\Length(min="50", max="1000")
     */
    protected $message;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="100")
     */
    protected $timeToReach;

    /**
     * @Assert\DateTime()
     */
    protected $when;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="100")
     */
    protected $time;

    /**
     * @Assert\Length(min="1", max="250")
     */
    protected $where;

    /**
     * Represents that the user has ticked the box confirming they are telling the truth.
     * @Assert\Type("bool")
     */
    protected $checkTruthful;

    /**
     * Represents that the user has ticked the box confirming they know after submitting the FNOL the information can
     * not be changed.
     * @Assert\Type("bool")
     */
    protected $checkPermanent;

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getSignature()
    {
        return $this->signature;
    }

    public function setSignature($signature)
    {
        $this->signature = $signature;
    }

    public function getPolicyNumber()
    {
        return $this->policyNumber;
    }

    public function setPolicyNumber($policyNumber)
    {
        $this->policyNumber = $policyNumber;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function getNetwork()
    {
        return $this->network;
    }

    public function setNetwork($network)
    {
        $this->network = $network;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function setMessage($message)
    {
        $this->message = $message;
    }

    public function getTimeToReach()
    {
        return $this->timeToReach;
    }

    public function setTimeToReach($timeToReach)
    {
        $this->timeToReach = $timeToReach;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
        if ($user) {
            $this->name = $user->getName();
            $this->email = $user->getEmail();
        }
    }

    public function getWhen($normalised = false)
    {
        if ($normalised) {
            $serializer = new Serializer(array(new DateTimeNormalizer()));
            return $serializer->normalize($this->when);
        } else {
            return $this->when;
        }
    }

    public function setWhen($when)
    {
        $this->when = $when;
    }

    public function getTime()
    {
        return $this->time;
    }

    public function setTime($time)
    {
        $this->time = $time;
    }

    public function getWhere()
    {
        return $this->where;
    }

    public function setWhere($where)
    {
        $this->where = $where;
    }

    /**
     * Gets the value of the truthful check box.
     * @return boolean|null the value of checkTruthful in the fnol. Will only be null if it has not been set.
     */
    public function getCheckTruthful()
    {
        return $this->checkTruthful;
    }

    /**
     * Sets the value of the fnol's truthful check box.
     * @param boolean $checkTruthful is the value of the truthful check.
     */
    public function setCheckTruthful($checkTruthful)
    {
        $this->checkTruthful = $checkTruthful;
    }

    /**
     * Gets the value of the fnol's awareness of permanence check box.
     * @return boolean|null the value of checkPermanent in the fnol. Will only be null if it has not been set.
     */
    public function getCheckPermanent()
    {
        return $this->checkPermanent;
    }

    /**
     * Sets the value of the fnol's permanent check box.
     * @param boolean $checkPermanent is the value of the truthful check.
     */
    public function setCheckPermanent($checkPermanent)
    {
        $this->checkPermanent = $checkPermanent;
    }

    public function getTypeString()
    {
        $claimType = '';
        switch ($this->type) {
            case 'damage':
                $claimType = 'DAMAGED OR BROKEN DOWN';
                break;
            case 'loss':
                $claimType = 'LOST';
                break;
            case 'theft':
                $claimType = 'STOLEN';
                break;
            default:
                $claimType = '';
                break;
        }
        return $claimType;
    }

    public static function getNetworks()
    {
        return Claim::$networks;
    }
}
