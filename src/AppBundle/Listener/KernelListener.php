<?php

namespace AppBundle\Listener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use AppBundle\Classes\SoSure;

class KernelListener
{
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
            $session->set('utm', serialize([
                'source' => $source,
                'medium' => $medium,
                'campaign' => $campaign,
                'term' => $request->query->get('utm_term'),
                'content' => $request->query->get('utm_content'),
            ]));
        }

        $referer = $request->headers->get('referer');
        if (strlen($referer) > 0) {
            $session = $request->getSession();
            $session->set('referer', $referer);
        }
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $response = $event->getResponse();
        $request  = $event->getRequest();
        $this->setSoSureTrackingCookie($request, $response);
        $this->setSoSureEmployeeCookie($response);
    }

    private function setSoSureTrackingCookie($request, $response)
    {
        $tracking = null;
        $session = $request->getSession();
        if ($session->isStarted()) {
            $tracking = $session->get(SoSure::SOSURE_TRACKING_SESSION_NAME);
        }

        if (!$tracking) {
            return;
        }
        $secure = in_array($this->environment, ['prod', 'staging']);

        // create/update cookie
        $cookie = new Cookie(
            SoSure::SOSURE_TRACKING_COOKIE_NAME,
            urlencode($tracking),
            time() + SoSure::SOSURE_TRACKING_COOKIE_LENGTH,
            '/',
            $this->domain,
            $secure,
            true
        );

        // set cookie in response
        $response->headers->setCookie($cookie);
    }

    private function setSoSureEmployeeCookie($response)
    {
        try {
            $token = $this->tokenStorage->getToken();
            if (!$token || !$token->getUser() || !$token->getUser() instanceof UserInterface) {
                return;
            }

            // authChecker doesn't seem to be working :(
            // WARNING - this means that we needs to directly check the assigned role instead of using role inheritance
            // TODO: Fixme
            $employee = false;
            foreach ($token->getUser()->getRoles() as $role) {
                if (in_array($role, ["ROLE_ADMIN", "ROLE_EMPLOYEE"])) {
                    $employee = true;
                }
            }
            if (!$employee) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $secure = in_array($this->environment, ['prod', 'staging']);

        // create/update cookie
        $cookie = new Cookie(
            SoSure::SOSURE_EMPLOYEE_COOKIE_NAME,
            urlencode($this->adminCookieValue),
            time() + SoSure::SOSURE_EMPLOYEE_COOKIE_LENGTH,
            '/',
            $this->domain,
            $secure,
            true
        );

        // set cookie in response
        $response->headers->setCookie($cookie);
    }
}
