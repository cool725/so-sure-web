<?php

namespace AppBundle\Event;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use FOS\UserBundle\Model\UserInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Claim;

class BacsEvent extends Event
{
    const EVENT_UPDATED = 'event.bacs.updated';

    /** @var UserInterface */
    protected $user;

    /** @var Policy */
    protected $policy;

    /** @var BankAccount */
    protected $bankAccount;

    public function __construct(BankAccount $bankAccount)
    {
        $this->bankAccount = $bankAccount;
    }

    public function getBankAccount()
    {
        return $this->bankAccount;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(UserInterface $user)
    {
        $this->user = $user;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getPolicyUserOrUser()
    {
        return $this->getPolicy() ? $this->getPolicy()->getUser() : $this->getUser();
    }
}
