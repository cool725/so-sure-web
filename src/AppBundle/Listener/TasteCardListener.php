<?php

namespace AppBundle\Listener;

use AppBundle\Service\PolicyService;
use AppBundle\Event\PolicyEvent;
use Psr\Log\LoggerInterface;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Document\Policy;
use AppBundle\Service\MailerService;

/**
 * Waits for policies to be cancelled to check if they have got taste cards and warn marketing if so.
 */
class TasteCardListener
{
    /** @var MailerService */
    protected $mailerService;

    /**
     * @param MailerService $mailerService
     */
    public function __construct(MailerService $mailerService)
    {
        $this->mailerService = $mailerService;
    }

    /**
     * If a policy is cancelled and it has a taste card set then we sent marketing an email.
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy->getTasteCard()) {
            if ($this->mailerService) {
                $this->mailerService->sendTemplate(
                    "Policy with TasteCard cancelled",
                    "marketing@so-sure.com",
                    'AppBundle:Email:policy/cancelledWithTasteCard.html.twig',
                    ['policy' => $policy],
                    'AppBundle:Email:policy/cancelledWithTasteCard.txt.twig',
                    ['policy' => $policy],
                    null,
                    'bcc@so-sure.com'
                );
            }
        }
    }
}
