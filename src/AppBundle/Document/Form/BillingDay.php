<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class BillingDay
{
    use DateTrait;

    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    /**
     * @Assert\Range(min="1", max="28")
     */
    protected $day;

    public function getDay()
    {
        return $this->day;
    }

    public function setDay($day)
    {
        $this->day = $day;

        $billingDate = $this->setDayOfMonth($this->getPolicy()->getBilling(), $day);
        $this->policy->setBilling($billingDate);
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
        $this->setDay($policy->getBilling()->format('j'));
    }
}
