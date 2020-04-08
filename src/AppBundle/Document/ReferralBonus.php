<?php

namespace AppBundle\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents a promise to give two policies a free month.
 * @MongoDB\Document()
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class ReferralBonus
{
    const STATUS_PENDING = 'pending';
    const STATUS_SLEEPING = 'sleeping';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_APPLIED = 'applied';
    const STATUS_RETRY = 'retry';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="inviterRefferalBonuses")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $inviter;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="inviteeRefferalBonuses")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $invitee;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * Records if the bonus has been given to the inviter yet.
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $inviterPaid = false;

    /**
     * Records if the bonus has been given to the invitee yet.
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $inviteePaid = false;

    /**
     * Records if the inviter has cancelled and therefore shouldn't be applied a bonus
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $inviterCancelled = false;

    /**
     * Status of the referral
     * @Assert\Choice({"pending", "sleeping", "cancelled", "applied", "retry"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    /**
     * Gives you the bonus's ID number.
     * @return string the id.
     */
    public function getId()
    {
        return $this->id;
    }

    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Sets the time at which the bonus was created.
     * @param \DateTime $created is the creation date.
     */
    public function setCreated(\DateTime $created)
    {
        $this->created = $created;
    }

    /**
     * Gives you the policy that invited resulting in the bonus.
     * @return Policy the inviter.
     */
    public function getInviter()
    {
        return $this->inviter;
    }

    /**
     * Sets the policy that invited resulting in the bonus.
     * @param Policy $inviter is the invitee.
     */
    public function setInviter(Policy $inviter)
    {
        $this->inviter = $inviter;
    }

    /**
     * Gives you the policy that was invited resulting in the bonus.
     * @return Policy the invitee.
     */
    public function getInvitee()
    {
        return $this->invitee;
    }

    /**
     * Sets the policy that was invited resulting in the bonus.
     * @param Policy $invitee is the invitee.
     */
    public function setInvitee($invitee)
    {
        $this->invitee = $invitee;
    }

    /**
     * Gives you the bonus's grandting date before which it can not be applied.
     * @return \DateTime the grant date.
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Sets the bonus's granting date which should also be it's creation date
     * as they are ready to be applied immediately.
     * @param \DateTime $date is the grant date.
     */
    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

    /**
     * Tells you the bonus's inviter paid status.
     * @return boolean the paid status.
     */
    public function getInviterPaid()
    {
        return $this->inviterPaid;
    }

    /**
     * Sets the bonus's inviter paid status.
     * @param boolean $paid is the paid status to give it.
     */
    public function setInviterPaid($paid)
    {
        $this->inviterPaid = $paid;
    }

    /**
     * Tells you the bonus's invitee paid status.
     * @return boolean the paid status.
     */
    public function getInviteePaid()
    {
        return $this->inviteePaid;
    }

    /**
     * Sets the bonus's invitee paid status.
     * @param boolean $paid is the paid status to give it.
     */
    public function setInviteePaid($paid)
    {
        $this->inviteePaid = $paid;
    }

    /**
     * Tells you the bonus's inviter paid status.
     * @return boolean the paid status.
     */
    public function getInviterCancelled()
    {
        return $this->inviterCancelled;
    }

    /**
     * Sets the bonus's inviter cencelled status.
     * @param boolean $cancelled
     */
    public function setInviterCancelled($cancelled)
    {
        $this->inviterCancelled = $cancelled;
    }

    /**
     * Get the referral status.
     * @return string status
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set the referral status.
     * @param string $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * Tells you the amount that the inviter shall get.
     * @return float the amount that they will get.
     */
    public function getAmountForInviter()
    {
        if ($this->getStatus = self::STATUS_CANCELLED) {
            return 0;
        }
        if ($this->getInviter()->getPremiumInstallments() == 1) {
            return $this->getInviter()->getUpgradedYearlyPremiumPrice() / 11;
        } else {
            return $this->getInviter()->getUpgradedStandardMonthlyPremiumPrice();
        }
    }

    /**
     * Tells you the amount that the invitee shall get.
     * @return float the amount they will get.
     */
    public function getAmountForInvitee()
    {
        if ($this->getStatus = self::STATUS_CANCELLED) {
            return 0;
        }
        if ($this->getInvitee()->getPremiumInstallments() == 1) {
            return $this->getInvitee()->getUpgradedYearlyPremiumPrice() / 11;
        } else {
            return $this->getInvitee()->getUpgradedStandardMonthlyPremiumPrice();
        }
    }

    /**
     * Tells you if the bonus can be applied to the inviter.
     * @param \DateTime $date is the date at which we are checking if it is applicable.
     * @return boolean true if it is applicable.
     */
    public function applicableToInviter($date)
    {
        return !$this->getInviterPaid() && $this->applicableToPolicy($this->getInviter(), $date);
    }

    /**
     * Tells you if the bonus can be applied to the invitee.
     * @param \DateTime $date is the date at which we are checking if it is applicable.
     * @return boolean true if it is applicable.
     */
    public function applicableToInvitee($date)
    {
        return !$this->getInviteePaid() && $this->applicableToPolicy($this->getInvitee(), $date);
    }

    /**
     * Does general logic for telling you if this bonus is applicable to either
     * it's inviter or it's invitee.
     * @param Policy    $policy is the policy you are checking on. If it is not the inviter or invitee the answer is
     *                          just going to be no.
     * @param \DateTime $date   is the date at which it must be applicable.
     * @return boolean true if it can be applied, and false if not.
     */
    public function applicableToPolicy($policy, $date)
    {
        if ($policy !== $this->getInvitee() && $policy !== $this->getInviter()) {
            return false;
        }
        if ($policy->getStatus() == Policy::STATUS_CANCELLED) {
            return false;
        }
        if ($this->getInviter()->isWithinCooloffPeriod($date, false) ||
            $this->getInvitee()->isWithinCooloffPeriod($date, false)
        ) {
            return false;
        }
        foreach ($policy->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED &&
                $scheduledPayment->getAmount() > 0
            ) {
                return true;
            }
        }
        return $policy->getPremiumInstallments() == 1 &&
            $policy->getPaidInviterReferralBonusAmount() + $policy->getPaidInviteeReferralBonusAmount() <=
            $policy->getUpgradedYearlyPrice() / 11 * 10;
    }
}
