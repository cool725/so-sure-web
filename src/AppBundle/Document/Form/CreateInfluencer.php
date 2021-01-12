<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\User;
use AppBundle\Document\Reward;
use AppBundle\Document\Influencer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents creating a reward which entails the creation of both user and reward records, and so this stores the
 * details required to do both.
 */
class CreateInfluencer extends CreateReward
{
    /**
     * @var string
     */
    protected $gender;

    /**
     * @var string
     */
    protected $organisation;

    public function setGender($gender)
    {
        $this->gender = $gender;
    }

    public function getGender()
    {
        return $this->gender;
    }

    public function setOrganisation($organisation)
    {
        $this->organisation = $organisation;
    }

    public function getOrganisation()
    {
        return $this->organisation;
    }
}
