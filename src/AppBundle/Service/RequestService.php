<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Document\User;
use Ramsey\Uuid\Uuid;

class RequestService
{
    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    protected $tokenStorage;

    /**
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestStack $requestStack,
        LoggerInterface $logger,
        $tokenStorage
    ) {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->tokenStorage = $tokenStorage;
    }

    public function getReferer()
    {
        return $this->getSession('referer');
    }

    public function getUtm()
    {
        $utm = $this->getSession('utm');
        if ($utm) {
            return unserialize($utm);
        }

        return null;
    }

    public function getSCode()
    {
        return $this->getSession('scode');
    }

    public function getSession($var = null)
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();
        if ($session->isStarted()) {
            if ($var) {
                return $session->get($var);
            } else {
                return $session;
            }
        }

        return null;
    }

    public function getUser()
    {
        if ($this->tokenStorage->getToken()) {
            $user = $this->tokenStorage->getToken()->getUser();
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    public function getUri()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            return $request->getUri();
        }

        return null;
    }

    public function getSessionId()
    {
        if ($session = $this->getSession()) {
            return $session->getId();
        }

        return null;
    }

    public function getClientIp()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            return $request->getClientIp();
        }

        return null;
    }

    public function getUserAgent()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            return $request->headers->get('User-Agent');
        }

        return null;
    }

    public function getTrackingId()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            if ($cookie = $request->cookies->get(SoSure::SOSURE_TRACKING_COOKIE_NAME)) {
                return urldecode($cookie);
            } elseif ($tracking = $this->getSession(SoSure::SOSURE_TRACKING_SESSION_NAME)) {
                return $tracking;
            } else {
                $uuid4 = Uuid::uuid4();
                if ($session = $this->getSession()) {
                    $session->set(SoSure::SOSURE_TRACKING_SESSION_NAME, $uuid4->toString());

                    return $uuid4->toString();
                }
            }
        }

        return null;
    }
}
