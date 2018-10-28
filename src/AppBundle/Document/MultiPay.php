<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document()
 * @Gedmo\Loggable
 */
class MultiPay
{
    const STATUS_REQUESTED = 'requested';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $actionDate;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     * @var User
     */
    protected $payer;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     * @var User
     */
    protected $payee;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\SCode")
     * @Gedmo\Versioned
     * @var SCode
     */
    protected $scode;

    /**
     * @Assert\Choice({"requested", "cancelled", "accepted", "rejected"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    public function __construct()
    {
        $this->createdDate = \DateTime::createFromFormat('U', time());
        $this->status = self::STATUS_REQUESTED;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    public function getActionDate()
    {
        return $this->actionDate;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
        if (in_array($status, [self::STATUS_ACCEPTED, self::STATUS_REJECTED])) {
            $this->actionDate = \DateTime::createFromFormat('U', time());
        }
    }

    /**
     * @return Policy
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * @return User
     */
    public function getPayee()
    {
        return $this->payee;
    }

    public function setPayee(User $payee)
    {
        $this->payee = $payee;
    }

    /**
     * @return User
     */
    public function getPayer()
    {
        return $this->payer;
    }

    public function setPayer(User $payer)
    {
        $this->payer = $payer;
    }

    /**
     * @return SCode
     */
    public function getSCode()
    {
        return $this->scode;
    }

    public function setSCode(SCode $scode)
    {
        $this->scode = $scode;
    }

    public function toApiArray()
    {
        return [
            'id' => $this->getId(),
            'date' => $this->getCreatedDate() ? $this->getCreatedDate()->format(\DateTime::ATOM) : null,
            'status' => $this->getStatus(),
            'policy_id' => $this->getPolicy()->getId(),
            'policy_number' => $this->getPolicy()->getPolicyNumber() ? $this->getPolicy()->getPolicyNumber() : null,
            'policy_user_name' => $this->getPayee()->getName(),
            'policy_status' => $this->getPolicy()->getStatus(),
            'policy_premium' => $this->getPolicy()->getPremium()->getMonthlyPremiumPrice(),
            'policy_premium_plan' => $this->getPolicy()->getPremiumPlan() ? $this->getPolicy()->getPremiumPlan() : null,
            'premium_payments' => $this->getPolicy()->getPremiumPayments(),
        ];
    }
}
