<?php

namespace AppBundle\Document\Opt;

use AppBundle\Document\IdentityLog;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(collection="OptOut")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("optout_type")
 * @MongoDB\DiscriminatorMap({"email"="EmailOptOut", "sms"="SmsOptOut", "optinEmail"="EmailOptIn"})
 */
abstract class Opt
{
    const OPTOUT_CAT_ALL = 'all';
    const OPTOUT_CAT_INVITATIONS = 'invitations';
    const OPTOUT_CAT_MARKETING = 'marketing'; // new category to replace aquire/retain

    const OPTIN_CAT_MARKETING = 'marketing';

    const OPT_LOCATION_PREFERNCES = 'preferences';
    const OPT_LOCATION_ADMIN = 'admin';
    const OPT_LOCATION_INTERCOM = 'intercom';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $updated;

    /**
     * Deprecated
     * @Assert\Choice({"all", "invitations", "weekly", "aquire", "retain", "marketing"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $category;

    /**
     * @Assert\Choice({"preferences", "admin", "intercom"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $location;

    /**
     * @MongoDB\Field(type="collection")
     */
    protected $categories = array();

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(max="1000")
     * @MongoDB\Field(type="string")
     */
    protected $notes;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\IdentityLog")
     */
    protected $identityLog;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getCreated()
    {
        return $this->created;
    }

    public function setUpdated($updated)
    {
        $this->updated = $updated;
    }

    public function getUpdated()
    {
        return $this->updated;
    }

    public function getCategory()
    {
        return $this->category;
    }

    public function setCategory($category)
    {
        $this->category = $category;
    }

    public function getCategories()
    {
        return $this->categories;
    }

    public function setCategories($categories)
    {
        if ($this->categories != $categories) {
            $this->setUpdated(new \DateTime());
        }
        $this->categories = $categories;
    }

    public function addCategory($category)
    {
        if (!in_array($category, $this->categories)) {
            $this->categories[] = $category;
            $this->setUpdated(new \DateTime());
        }
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        if ($this->notes != $notes) {
            $this->setUpdated(new \DateTime());
        }
        $this->notes = $notes;
    }

    public function getLocation()
    {
        return $this->location;
    }

    public function setLocation($location)
    {
        if ($this->location != $location) {
            $this->setUpdated(new \DateTime());
        }
        $this->location = $location;
    }

    public function getIdentityLog()
    {
        return $this->identityLog;
    }

    public function setIdentityLog(IdentityLog $identityLog)
    {
        $this->identityLog = $identityLog;
    }
}
