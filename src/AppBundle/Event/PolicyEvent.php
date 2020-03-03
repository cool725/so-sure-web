<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;

class PolicyEvent extends Event
{
    const EVENT_INIT = 'event.policy.init';
    const EVENT_CREATED = 'event.policy.created';
    const EVENT_CANCELLED = 'event.policy.cancelled';
    const EVENT_EXPIRED = 'event.policy.expired';
    const EVENT_PENDING_RENEWAL = 'event.policy.pending-renewal';
    const EVENT_RENEWED = 'event.policy.renewed';
    const EVENT_DECLINED_RENEWAL = 'event.policy.declined-renewal';
    const EVENT_START = 'event.policy.start';
    const EVENT_CASHBACK = 'event.policy.cashback';
    const EVENT_UNPAID = 'event.policy.unpaid';
    const EVENT_REACTIVATED = 'event.policy.reactived';
    const EVENT_BACS_CREATED = 'event.policy.bacs-created';
    const EVENT_UPDATED_PREMIUM = 'event.policy.updated-premium';
    const EVENT_UPDATED_BILLING = 'event.policy.updated-billing';
    const EVENT_UPGRADED = 'event.policy.upgraded';

    const EVENT_UPDATED_STATUS = 'event.policy.updated-status';

    // Certain changes to a policy (user) should trigger a new salva version
    const EVENT_SALVA_INCREMENT = 'event.policy.salva_increment';

    const EVENT_UPDATED_POT = 'event.policy.pot';

    const EVENT_PAYMENT_METHOD_CHANGED = 'event.policy.payment-method.changed';

    const EVENT_UPDATED_HUBSPOT = 'event.policy.updated.hubspot';

    /** @var Policy */
    protected $policy;
    protected $date;
    protected $previousStatus;

    /** @var string */
    protected $previousPaymentMethod;

    public function __construct(Policy $policy, \DateTime $date = null)
    {
        $this->policy = $policy;
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $this->date = $date;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setPreviousStatus($previousStatus)
    {
        $this->previousStatus = $previousStatus;
    }

    public function getPreviousStatus()
    {
        return $this->previousStatus;
    }

    public function getPreviousPaymentMethod()
    {
        return $this->previousPaymentMethod;
    }

    public function setPreviousPaymentMethod($previousPaymentMethod)
    {
        $this->previousPaymentMethod = $previousPaymentMethod;
    }
}
