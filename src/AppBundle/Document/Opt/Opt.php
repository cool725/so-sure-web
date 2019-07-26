<?php

namespace AppBundle\Document\Opt;

use AppBundle\Document\IdentityLog;
use AppBundle\Document\Lead;
use AppBundle\Document\User;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(collection="OptOut")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("optout_type")
 * @MongoDB\DiscriminatorMap({"email"="EmailOptOut", "sms"="SmsOptOut", "optinEmail"="EmailOptIn"})
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Opt
{
    const OPTOUT_CAT_ALL = 'all';
    const OPTOUT_CAT_INVITATIONS = 'invitations';
    const OPTOUT_CAT_MARKETING = 'marketing'; // new category to replace aquire/retain

    const OPTIN_CAT_MARKETING = 'marketing';

    const OPT_LOCATION_PREFERENCES = 'preferences';
    const OPT_LOCATION_ADMIN = 'admin';
    const OPT_LOCATION_INTERCOM = 'intercom';
    const OPT_LOCATION_POS = 'pos';

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
     * @Assert\Choice({"preferences", "admin", "intercom", "pos"}, strict=true)
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

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="opts")
     * @Gedmo\Versioned
     * @var User
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Lead", inversedBy="opts")
     * @Gedmo\Versioned
     * @var Lead
     */
    protected $lead;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
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
            $this->setUpdated(\DateTime::createFromFormat('U', time()));
        }
        $this->categories = $categories;
    }

    public function addCategory($category)
    {
        if (!in_array($category, $this->categories)) {
            $this->categories[] = $category;
            $this->setUpdated(\DateTime::createFromFormat('U', time()));
        }
    }

    /**
     * Tells you if the opt has a given category set.
     * @param string $category is the category to look for.
     */
    public function hasCategory($category)
    {
        return in_array($category, $this->categories);
    }
    
    /**
     * Removes a category if it is there, or just does nothing.
     * @param string $category is the category to remove.
     */
    public function removeCategory($category)
    {
        $index = array_search($category, $this->categories);
        if ($index !== false) {
            array_splice($this->categories, $index, 1);
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
            $this->setUpdated(\DateTime::createFromFormat('U', time()));
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
            $this->setUpdated(\DateTime::createFromFormat('U', time()));
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

    /**
     * @return User|null
     */
    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * @return Lead|null
     */
    public function getLead()
    {
        return $this->lead;
    }

    public function setLead(Lead $lead)
    {
        $this->lead = $lead;
    }
}
