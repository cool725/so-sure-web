<?php

namespace AppBundle\Listener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\User;

class SecurityListener
{
    protected $logger;

    /** @var RequestStack */
    protected $requestStack;

    public function __construct($logger, RequestStack $requestStack)
    {
        $this->logger = $logger;
        $this->requestStack = $requestStack;
    }

    /**
     * @param InteractiveLoginEvent $event
     */
    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event)
    {
        if (!$event->getAuthenticationToken() || !$event->getAuthenticationToken()->getUser() instanceof User) {
            return;
        }

        $identityLog = new IdentityLog();
        $identityLog->setIp($this->requestStack->getCurrentRequest()->getClientIp());
        $user = $event->getAuthenticationToken()->getUser();
        $user->setLatestWebIdentityLog($identityLog);
    }
}