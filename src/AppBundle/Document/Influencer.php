<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Reward;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\InfluencerRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Influencer extends Reward
{
    const LIMIT_USAGE = 1000; // Should be Unlimited
    const DEFAULT_REWARD = 0; // Amazon gift card
    const DEFAULT_TYPE = 'Influencer';
    const DEFAULT_TARGET = 'Referrals';

    /**
     * @Assert\Email()
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $email;


    /**
     * @Assert\Length(min="1", max="150")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $organisation;

    /**
     * @Assert\Length(min="1", max="150")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $gender;

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function getOrganisation()
    {
        return $this->organisation;
    }

    public function setOrganisation($organisation)
    {
        $this->organisation = $organisation;
    }

    public function getGender()
    {
        return $this->gender;
    }

    public function setGender($gender)
    {
        $this->gender = $gender;
    }
}
