<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document()
 */
class Sanctions
{
    const SOURCE_UK_TREASURY = 'uk-treasury';

    const TYPE_USER = 'user';
    const TYPE_COMPANY = 'company';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\NotNull()
     * @Assert\Choice({"uk-treasury"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $source;

    /**
     * @Assert\NotNull()
     * @Assert\Choice({"user", "company"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $type;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $date;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $company;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $lastName;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $firstName;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $birthday;

    public function __construct()
    {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function setSource($source)
    {
        $this->source = $source;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;
    }

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function getFirstNameSpaceless()
    {
        return str_replace(' ', '-', $this->getFirstName());
    }

    public function setLastName($lastName)
    {
        $this->lastName = $lastName;
    }

    public function getLastName()
    {
        return $this->lastName;
    }
    
    public function getLastNameSpaceless()
    {
        return str_replace(' ', '-', $this->getLastName());
    }

    public function getCompany()
    {
        return $this->company;
    }

    public function setCompany($company)
    {
        $this->company = $company;
    }

    public function getName()
    {
        if ($this->firstName) {
            return sprintf('%s %s', $this->firstName, $this->lastName);
        } else {
            return $this->lastName;
        }
    }

    public function getBirthday()
    {
        return $this->birthday;
    }
}
