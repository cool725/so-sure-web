<?php

namespace AppBundle\Document;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Validator\Constraints as AppAssert;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Stores an iteration of a policy that has been upgraded to something else.
 * @MongoDB\EmbeddedDocument()
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhonePolicyIteration
{
    /**
     * When this iteration was created.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * When this iteration started.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $start;

    /**
     * When this iteration started really, as in to the second.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $realStart;

    /**
     * When this iteration ended.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $end;

    /**
     * When this iteration ended really, not truncated to days.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $realEnd;

    /**
     * The model of the phone that was insured.
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    protected $phone;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $phoneData;

    /**
     * The imei number of the insured phone.
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $imei;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $serialNumber;

    /**
     * The premium that the policy had at this time.
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Premium")
     * @Gedmo\Versioned
     * @var Premium
     */
    protected $premium;

    /**
     * Creates the iteration and sets the created time to the current time.
     */
    public function __construct()
    {
        $this->created = new \DateTime();
    }

    /**
     * Gives you the created date.
     * @return \DateTime the created date.
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Sets the created date.
     * @param \DateTime $created is the date to set it to.
     */
    public function setCreated($created)
    {
        $this->created = $created;
    }

    /**
     * Gives you the start date of the iteration.
     * @return \DateTime the start.
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * Gives you the genuine start date of the iteration.
     * @return \DateTime the real start.
     */
    public function getRealStart()
    {
        return $this->realStart;
    }

    /**
     * Sets the start date of the iteration.
     * @param \DateTime $start is the start date.
     */
    public function setStart($start)
    {
        $this->realStart = clone $start;
        $this->start = DateTrait::startOfDay($start);
    }

    /**
     * Gives you the end date of this iteration.
     * @return \DateTime the end of the iteration.
     */
    public function getEnd()
    {
        return $this->end;
    }

    /**
     * Gives you the time and date that this iteration really ended.
     * @return \DateTime the real end of the iteration.
     */
    public function getRealEnd()
    {
        return $this->realEnd;
    }

    /**
     * Sets the end of the iteration.
     * @param \DateTime $end is the end of the iteration.
     */
    public function setEnd($end)
    {
        $this->realEnd = clone $end;
        $this->end = DateTrait::startOfDay($end);
    }

    /**
     * Gives you the phone of the iteration.
     * @return Phone the phone.
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Sets the phone of the iteration.
     * @param Phone $phone is the phone.
     */
    public function setPhone($phone)
    {
        $this->phone = $phone;
    }

    /**
     * Gives you the phone data record for this iteration.
     * @return string the phone data.
     */
    public function getPhoneData()
    {
        return $this->phoneData;
    }

    /**
     * Sets the phone data record for this iteration.
     * @param string $phoneData is the phone data to set.
     */
    public function setPhoneData($phoneData)
    {
        $this->phoneData = $phoneData;
    }

    /**
     * Gives you the phone's IMEI number.
     * @return string the imei.
     */
    public function getImei()
    {
        return $this->imei;
    }

    /**
     * Sets the phone's IMEI number.
     * @param string $imei is the imei number to use.
     */
    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    /**
     * Gives you the iterations's serial number.
     * @return string|null the serial number if there is one or null otherwise.
     */
    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    /**
     * Sets the iteration's phone's serial number to something.
     * @param string $serialNumber is the serial number to give it.
     */
    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    /**
     * Gives you the iteration's premium.
     * @return Premium the premium.
     */
    public function getPremium()
    {
        return $this->premium;
    }

    /**
     * Sets the iteration's premium.
     * @param Premium $premium is the premium to set on it.
     */
    public function setPremium($premium)
    {
        $this->premium = $premium;
    }

    public function inIteration($date)
    {
        return $date >= $this->getStart() and $date < $this->getEnd();
    }

    /**
     * Gives you the time period that this iteration covers. Can be used to find the proportion of the policy that this
     * iteration covers by dividing it by the period of the policy.
     * @return int the number of days in this iteration.
     */
    public function getPeriod()
    {
        return $this->getStart()->diff($this->getEnd())->days;
    }

    /**
     * Gives you the amount of premium that this iteration deserves.
     * @param int $days is the number of days in the policy so that it knows what it is a proportion of.
     * @return float the amount of premium needed.
     */
    public function getProRataPremium($days)
    {
        return $this->getPeriod() / $days * $this->getPremium()->getAdjustedYearlyPremiumPrice();
    }

    /**
     * Gives you the amount of IPT that this iteration deserves.
     * @param int $days is the number of days in the policy so that it knows what it is a proportion of.
     * @return float the amount of ipt needed.
     */
    public function getProRataIpt($days)
    {
        return $this->getPeriod() / $days * $this->getPremium()->getYearlyIpt();
    }
}
