<?php

namespace AppBundle\Document;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\User;
use AppBundle\Classes\Salva;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents an offer to a user for a lowered premium on a given model of phone.
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\OfferRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Offer
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * Time at which the offer was created. Does not need to be used for any functionality but is kept for reference.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * Creator of the offer.
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     * @var User
     */
    protected $author;

    /**
     * The offered price.
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Price")
     * @Gedmo\Versioned
     * @var Price
     */
    protected $price;

    /**
     * The phone model for which this offer is made.
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    protected $phone;

    /**
     * The name of this offer. Not intended to be seen by users, just so admins can keep track of multiple offers on
     * the same phone and such.
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    /**
     * Whether the offer is currently enabled.
     * @MongoDB\Field(type="boolean")
     */
    protected $active;

    /**
     * Contains all users that this offer is offered to.
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\User", inversedBy="offers")
     * @var ArrayCollection
     */
    protected $users;

    /**
     * Contains all policies that are using the price from this offer.
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Policy")
     * @var ArrayCollection
     */
    protected $policies = [];

    /**
     * Gives you the offer's id.
     * @return string the offer's id.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Gives the time at which the offer was created.
     * @return \DateTime of creation.
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Sets the time at which this offer was created.
     * @param \DateTime $created is the time at which it was created.
     */
    public function setCreated($created)
    {
        $this->created = $created;
    }

    /**
     * Gives you the creator of the offer.
     * @return User the author.
     */
    public function getAuthor()
    {
        return $this->author;
    }

    /**
     * Sets the author of the offer.
     * @param User $author is the one who created it.
     */
    public function setAuthor($author)
    {
        $this->author = $author;
    }

    /**
     * Gives the price that this offer pertains to.
     * @return PhonePrice the price.
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Sets the price for this offer.
     * @param PhonePrice $price the price.
     */
    public function setPrice($price)
    {
        $this->price = $price;
    }

    /**
     * Gives the phone that this offer is about.
     * @return Phone the phone.
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Sets what phone model this offer is about.
     * @param Phone $phone is the phone that the offer is about.
     */
    public function setPhone($phone)
    {
        $this->phone = $phone;
    }

    /**
     * Gives you the name of this offer.
     * @return string the name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets the name of the offer.
     * @param string $name is the new name to give.
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Tells you whether the offer is active.
     * @return boolean whether it is active.
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Sets the offer's activity status.
     * @param boolean $active is the value to set it to.
     */
    public function setActive($active)
    {
        $this->active = $active;
    }

    /**
     * Gives you the list of affected users.
     * @return array containing the users.
     */
    public function getUsers()
    {
        if (is_array($this->users)) {
            return $this->users;
        }
        return $this->users->toArray();
    }

    /**
     * Adds a user to the list of users to whom this offer is applied.
     * @param User $user is the user to give it to.
     */
    public function addUser($user)
    {
        $this->users[] = $user;
    }

    /**
     * Gives a list of the policies affected by this offer.
     * @return array of policies that have used this offer themselves.
     */
    public function getPolicies()
    {
        if (is_array($this->policies)) {
            return $this->policies;
        }
        return $this->policies->toArray();
    }

    /**
     * Adds a policy to the offer's list of policies it has been used on.
     * @param Policy $policy is the policy to add to the list.
     */
    public function addPolicy($policy)
    {
        // TODO: should this do anything else like set the offer on the policy?
        $this->policies[] = $policy;
    }

    /**
     * Turns the details of the offer into an array that can be sent to JSON endpoints and such.
     * @return array with field name being the name of the offer and field users being an array containing arrays with
     *               field email being the email of the user and field policies being an array containing arrays with
     *               field policy number being the policy's policy number and field start being the policy's start
     *               date.
     */
    public function toArray()
    {
        return [
            "name" => $this->getName(),
            "users" => array_map(function($user) {
                $policies = $user->getPolicies();
                $policies = is_array($policies) ? $policies : $policies->toArray();
                return [
                    "email" => $user->getEmail(),
                    "policies" => array_map(function($policy) {
                        return [
                            "policyNumber" => $policy->getPolicyNumber(),
                            "start" => $policy->getStart()
                        ];
                    }, $policies)
                ];
            }, $this->getUsers())
        ];
    }
}
