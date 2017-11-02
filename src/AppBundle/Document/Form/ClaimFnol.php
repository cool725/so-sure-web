<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimFnol
{
    use DateTrait;

    /**
     * @var Policy
     */
    protected $policy;

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
     * @AppAssert\AlphanumericSpaceDot()
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
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="10", max="200")
     */
    protected $where;

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

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
        if ($policy) {
            $this->policyNumber = $policy->getPolicyNumber();
            $this->setUser($policy->getUser());
        }
    }

    public function setUser(User $user)
    {
        if ($user) {
            $this->name = $user->getName();
            $this->email = $user->getEmail();
            $this->phone = $user->getMobileNumber();
        }
    }
    
    public function getWhen()
    {
        return $this->when;
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
}
