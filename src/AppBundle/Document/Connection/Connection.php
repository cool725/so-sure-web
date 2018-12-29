<?php

namespace AppBundle\Document\Connection;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ConnectionRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({
 *      "standard"="StandardConnection",
 *      "reward"="RewardConnection",
 *      "renewal"="RenewalConnection"
 * })
 * @MongoDB\Index(keys={"sourcePolicy.id"="asc"}, sparse="true")
 * @MongoDB\Index(keys={"linkedPolicy.id"="asc"}, sparse="true")
 */
class Connection
{
    use CurrencyTrait;

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     * @var User
     */
    protected $linkedUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     * @var User
     */
    protected $sourceUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $sourcePolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Invitation\Invitation", cascade={"persist"})
     * @Gedmo\Versioned
     * @var Invitation
     */
    protected $invitation;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $initialInvitationDate;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="acceptedConnections")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $linkedPolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="acceptedConnectionsRenewal")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $linkedPolicyRenewal;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $value;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $promoValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialPromoValue;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Connection\Connection")
     * @Gedmo\Versioned
     */
    protected $replacementConnection;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $excludeReporting;

    public function __construct()
    {
        $this->date = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return User
     */
    public function getLinkedUser()
    {
        return $this->linkedUser;
    }

    public function setLinkedUser(User $user)
    {
        $this->linkedUser = $user;
    }

    /**
     * @return Invitation
     */
    public function getInvitation()
    {
        return $this->invitation;
    }

    public function setInvitation(Invitation $invitation)
    {
        $this->invitation = $invitation;
    }

    public function getInitialInvitationDate()
    {
        return $this->initialInvitationDate;
    }

    public function setInitialInvitationDate(\DateTime $initialInvitationDate)
    {
        $this->initialInvitationDate = $initialInvitationDate;
    }

    /**
     * @return User
     */
    public function getSourceUser()
    {
        return $this->sourceUser;
    }

    public function setSourceUser(User $user)
    {
        $this->sourceUser = $user;
    }

    /**
     * @return Policy
     */
    public function getSourcePolicy()
    {
        return $this->sourcePolicy;
    }

    public function setSourcePolicy(Policy $policy)
    {
        $this->validateSelfConnection($policy, $this->getLinkedPolicy());

        $this->sourcePolicy = $policy;
    }

    /**
     * @return Policy
     */
    public function getLinkedPolicy()
    {
        return $this->linkedPolicy;
    }

    public function setLinkedPolicy(Policy $policy)
    {
        $this->validateSelfConnection($policy, $this->getSourcePolicy());

        $this->linkedPolicy = $policy;
        $policy->addAcceptedConnection($this);
    }

    private function validateSelfConnection($policyA, $policyB)
    {
        if (!$policyA || !$policyB || !$policyA->getId() || !$policyB->getId()) {
            return;
        }

        if ($policyB->getId() == $policyA->getId()) {
            throw new \Exception('Policy can not be linked to itself');
        } elseif ($policyB->getNextPolicy() &&
            $policyB->getNextPolicy()->getId() == $policyA->getId()) {
            throw new \Exception('Policy can not be linked to its renewal policy');
        } elseif ($policyB->getPreviousPolicy() &&
            $policyB->getPreviousPolicy()->getId() == $policyA->getId()) {
            throw new \Exception('Policy can not be linked to its previous policy');
        }
    }

    public function getLinkedPolicyRenewal()
    {
        return $this->linkedPolicyRenewal;
    }

    public function setLinkedPolicyRenewal(Policy $policy)
    {
        if ($this->getId() && $this->getSourcePolicy() && $this->getSourcePolicy()->getId() == $policy->getId()) {
            throw new \Exception('Policy can not be linked to itself');
        }

        $this->linkedPolicyRenewal = $policy;
        $policy->addAcceptedConnectionRenewal($this);
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date = null)
    {
        $this->date = $date;
    }

    public function getValue()
    {
        return $this->value ? $this->value : 0;
    }

    public function setValue($value)
    {
        $this->value = $value;
        if (!$this->getInitialValue()) {
            $this->initialValue = $value;
        }
    }

    public function getPromoValue()
    {
        return $this->promoValue ? $this->promoValue : 0;
    }

    public function setPromoValue($promoValue)
    {
        $this->promoValue = $promoValue;
        if (!$this->getInitialPromoValue()) {
            $this->initialPromoValue = $promoValue;
        }
    }

    public function getTotalValue()
    {
        return $this->getValue() + $this->getPromoValue();
    }

    public function setExcludeReporting($excludeReporting)
    {
        $this->excludeReporting = $excludeReporting;
    }

    public function getExcludeReporting()
    {
        return $this->excludeReporting;
    }

    public function clearValue()
    {
        $this->value = 0;
        $this->promoValue = 0;
    }

    /**
     * If connected for > 6 months, then take monthly prorated value for connection
     */
    public function prorateValue(\DateTime $date = null)
    {
        // only prorate connection value if the policy is still active
        if ($this->getSourcePolicy() && !$this->getSourcePolicy()->isActive()) {
            return;
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $diff = $date->diff($this->getDate());
        // print $date->format(\DateTime::ATOM) . PHP_EOL;
        // print_r($diff);
        $totalMonths = $diff->y * 12 + $diff->m;
        if ($totalMonths < 6) {
            return $this->clearValue();
        } elseif ($totalMonths <= 11 && $diff->days < (365-15)) {
            $this->value = $this->toTwoDp($this->value * $totalMonths / 12);
            $this->promoValue = $this->toTwoDp($this->promoValue * $totalMonths / 12);
        }
        // 15 days to expiration or less should just keep value
        // >= 12 months should just keep value
    }

    public function getInitialValue()
    {
        return $this->initialValue;
    }

    public function getInitialPromoValue()
    {
        return $this->initialPromoValue;
    }

    public function getReplacementConnection()
    {
        return $this->replacementConnection;
    }

    public function setReplacementConnection($replacementConnection)
    {
        $this->replacementConnection = $replacementConnection;
    }

    public function toApiArray($claims)
    {
        $claimDates = [];
        if ($claims) {
            foreach ($claims as $claim) {
                if ($this->getLinkedPolicy() &&
                    $claim->getPolicy()->getId() == $this->getLinkedPolicy()->getId() &&
                    $claim->getClosedDate()) {
                    $claimDates[] =  $claim->getClosedDate()->format(\DateTime::ATOM);
                } elseif ($this->getLinkedPolicyRenewal() &&
                    $claim->getPolicy()->getId() == $this->getLinkedPolicyRenewal()->getId() &&
                    $claim->getClosedDate()) {
                    $claimDates[] =  $claim->getClosedDate()->format(\DateTime::ATOM);
                }
            }
        }

        return [
            'name' => $this->getLinkedUser() ? $this->getLinkedUser()->getName() : null,
            'date' => $this->getDate() ? $this->getDate()->format(\DateTime::ATOM) : null,
            'claim_dates' => $claimDates,
            'id' => $this->getId(),
            'image_url' => $this->getLinkedUser() ? $this->getLinkedUser()->getImageUrl() : null,
            'value' => $this->toTwoDp($this->getTotalValue()),
            'facebook_id' => $this->getLinkedUser() ? $this->getLinkedUser()->getFacebookId() : null,
            'policy_id' => $this->getLinkedPolicy() ? $this->getLinkedPolicy()->getId() : null,
            'reconnect_on_renewal' => $this instanceof RenewalConnection ? $this->getRenew() : null,
        ];
    }

    public function createRenewal($renew = true)
    {
        $renewalConnection = new RenewalConnection();
        if ($this->getLinkedPolicyRenewal()) {
            $renewalConnection->setLinkedPolicy($this->getLinkedPolicyRenewal());
        } else {
            $renewalConnection->setLinkedPolicy($this->getLinkedPolicy());
        }
        $renewalConnection->setLinkedUser($this->getLinkedUser());

        // default to renew the connection
        $renewalConnection->setRenew($renew);

        return $renewalConnection;
    }

    public function findInversedConnection()
    {
        if (!$this->getSourcePolicy()) {
            return;
        }
        foreach ($this->getSourcePolicy()->getAcceptedConnectionsRenewal() as $connection) {
            if ($connection->getLinkedPolicyRenewal() &&
                $connection->getLinkedPolicyRenewal()->getId() == $this->getSourcePolicy()->getId()) {
                return $connection;
            }
        }
        foreach ($this->getSourcePolicy()->getAcceptedConnections() as $connection) {
            if ($this->getLinkedPolicy() &&
                $this->getLinkedPolicy()->getId() == $connection->getSourcePolicy()->getId()) {
                return $connection;
            }
        }

        return null;
    }

    public function getLinkedClaimsDuringPeriod()
    {
        $claims = [];
        $periodStart = clone $this->getSourcePolicy()->getStart();
        $periodEnd = clone $this->getSourcePolicy()->getEnd();
        if ($policy = $this->getLinkedPolicyRenewal()) {
            foreach ($policy->getClaims() as $claim) {
                if ($claim->getLossDate() && $claim->getLossDate() >= $periodStart &&
                    $claim->getLossDate() < $periodEnd) {
                    $claims[] = $claim;
                }
            }
        }
        if ($policy = $this->getLinkedPolicy()) {
            foreach ($policy->getClaims() as $claim) {
                if ($claim->getLossDate() && $claim->getLossDate() >= $periodStart &&
                    $claim->getLossDate() < $periodEnd) {
                    $claims[] = $claim;
                }
            }
        }

        return $claims;
    }
}
