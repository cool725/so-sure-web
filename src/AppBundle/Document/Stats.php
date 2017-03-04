<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\StatsRepository")
 */
class Stats
{
    const INSTALL_APPLE = 'install-apple';
    const INSTALL_GOOGLE = 'install-google';
    const MIXPANEL_TOTAL_SITE_VISITORS = 'mixpanel-total-site-visitors';
    const MIXPANEL_QUOTES_UK = 'mixpanel-quotes-uk';
    const MIXPANEL_RECEIVE_PERSONAL_DETAILS = 'mixpanel-receive-personal-details';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $date;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true)
     */
    protected $name;

    /**
     * @MongoDB\Field(type="float", nullable=false)
     */
    protected $value;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }
    
    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }
}
