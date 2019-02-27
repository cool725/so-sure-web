<?php

namespace AppBundle\Listener;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Service\BacsService;
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

    /** @var BacsService */
    protected $bacsService;

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param BankAccountNameValidator $bankAccountNameValidator
     * @param BacsService              $bacsService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        BankAccountNameValidator $bankAccountNameValidator,
        BacsService $bacsService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->bankAccountNameValidator = $bankAccountNameValidator;
        $this->bacsService = $bacsService;
    }

    /**
     * @param UserEvent $event
     */
    public function onUserNameChangedEvent(UserEvent $event)
    {
        $user = $event->getUser();
        foreach ($user->getValidPolicies(true) as $policy) {
            /** @var Policy $policy */
            if ($policy->hasBacsPaymentMethod()) {
                $bankAccount = $policy->getBacsBankAccount();
                if ($bankAccount && !$this->bankAccountNameValidator->isAccountName(
                    $bankAccount->getAccountName(),
                    $user
                )) {
                    $bankAccount->setMandateStatus(BankAccount::MANDATE_CANCELLED);
                    $this->bacsService->notifyMandateCancelledByNameChange($user);
                }
            }
        }
    }

    /**
     * @param BacsEvent $event
     */
    public function onBankAccountChangedEvent(BacsEvent $event)
    {
        $bankAccount = $event->getBankAccount();
        $this->bacsService->queueCancelBankAccount($bankAccount, $event->getPolicyUserOrUser()->getId());
    }

    public function onPolicyBacsCreated(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $this->bacsService->queueBacsCreated($policy);
    }

    public function onPolicyUpdatedPremium(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy->hasPolicyOrUserBacsPaymentMethod()) {
            $this->logger->error(sprintf(
                'Unexpected premium change for policy %s with Bacs Payment method. Mandate should be invalidated?',
                $policy->getId()
            ));
        }
    }

    public function onPolicyUpdatedBilling(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy->hasPolicyOrUserBacsPaymentMethod()) {
            $this->logger->error(sprintf(
                'Unexpected billing date change for policy %s with Bacs Payment method. Currently not allowed',
                $policy->getId()
            ));
        }
    }
}
