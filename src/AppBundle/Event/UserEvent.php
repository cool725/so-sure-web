<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\User;

class UserEvent extends Event
{
    const EVENT_CREATED = 'event.user.created';
    const EVENT_UPDATED_INTERCOM = 'event.user.updated.intercom';
    const EVENT_UPDATED_INVITATION_LINK = 'event.user.updated.invitation-link';
    const EVENT_NAME_UPDATED = 'event.user.name.updated';
    const EVENT_PASSWORD_CHANGED = 'event.user.password.changed';
    const EVENT_PAYMENT_METHOD_CHANGED = 'event.user.payment-method.changed';

    /** @var User */
    protected $user;

    /** @var string */
    protected $previousPaymentMethod;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser()
    {
        return $this->user;
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
