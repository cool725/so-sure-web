<?php

namespace AppBundle\Listener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class KernelListener
{
    const SOSURE_EMPLOYEE_COOKIE_NAME = 'sosure-employee';
    const SOSURE_EMPLOYEE_COOKIE_LENGTH = 604800; // 7 days
    protected $tokenStorage;
    protected $authChecker;
    protected $adminCookieValue;
    protected $logger;
    protected $environment;
    protected $domain;

    public function __construct($tokenStorage, $authChecker, $adminCookieValue, $logger, $environment, $domain)
    {
        $this->tokenStorage = $tokenStorage;
        $this->authChecker = $authChecker;
        $this->adminCookieValue = $adminCookieValue;
        $this->logger = $logger;
        $this->environment = $environment;
        $this->domain = $domain;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        
        $request  = $event->getRequest();
        $source = $request->query->get('utm_source');
        $medium = $request->query->get('utm_medium');
        $campaign = $request->query->get('utm_campaign');
        if ($source || $medium || $campaign) {
            $session = $request->getSession();
            $session->set('utm', serialize(['source' => $source, 'medium' => $medium, 'campaign' => $campaign]));
        }
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $response = $event->getResponse();
        $request  = $event->getRequest();

        try {
            $token = $this->tokenStorage->getToken();
            if (!$token || !$token->getUser() || !$token->getUser() instanceof UserInterface) {
                return;
            }

            // authChecker doesn't seem to be working :(
            // WARNING - this means that ROLE_ADMIN needs to be directly assigned instead of chainging
            // TODO: Fixme
            $adminUser = false;
            foreach ($token->getUser()->getRoles() as $role) {
                if ($role == "ROLE_ADMIN") {
                    $adminUser = true;
                }
            }
            if (!$adminUser) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $secure = $this->environment == 'prod';

        // create/update cookie
        $cookie = new Cookie(
            self::SOSURE_EMPLOYEE_COOKIE_NAME,
            urlencode($this->adminCookieValue),
            time() + self::SOSURE_EMPLOYEE_COOKIE_LENGTH,
            '/',
            $this->domain,
            $secure,
            true
        );

        // set cookie in response
        $response->headers->setCookie($cookie);
    }
}
