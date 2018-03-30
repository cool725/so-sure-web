<?php

namespace AppBundle\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Service\MailerService;
use AppBundle\Validator\Constraints\BankAccountNameValidator;
use Doctrine\ODM\MongoDB\DocumentManager;
use Documents\Event;
use Psr\Log\LoggerInterface;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\FOSUserEvents;

class BacsListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var BankAccountNameValidator */
    protected $bankAccountNameValidator;

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param BankAccountNameValidator $bankAccountNameValidator
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        BankAccountNameValidator $bankAccountNameValidator
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->bankAccountNameValidator = $bankAccountNameValidator;
    }

    /**
     * @param UserEvent $event
     */
    public function onUserNameChangedEvent(UserEvent $event)
    {
        $user = $event->getUser();
        $paymentMethod = $user->getPaymentMethod();
        if ($paymentMethod && $paymentMethod->getType() == "bacs") {
            /** @var BacsPaymentMethod $paymentMethod */
            $bankAccount = $paymentMethod->getBankAccount();
            if ($bankAccount && !$this->bankAccountNameValidator->isAccountName(
                $bankAccount->getAccountName(),
                $user
            )) {
                $bankAccount->setMandateStatus(BankAccount::MANDATE_CANCELLED);
            }
        }
    }
}
