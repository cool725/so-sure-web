<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Document\User;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\IpUtils;
use Mobile_Detect;

class RequestService
{
    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    protected $tokenStorage;
    protected $adminCookieValue;
    protected $mobileDetect;

    /**
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     * @param                 $tokenStorage
     * @param string          $adminCookieValue
     */
    public function __construct(
        RequestStack $requestStack,
        LoggerInterface $logger,
        $tokenStorage,
        $adminCookieValue
    ) {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->tokenStorage = $tokenStorage;
        $this->adminCookieValue = $adminCookieValue;
        if ($request = $this->requestStack->getCurrentRequest()) {
            $this->mobileDetect = new Mobile_Detect($this->requestStack->getCurrentRequest()->server->all());
        }
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

    public function isMobileDevice()
    {
        return $this->mobileDetect->isMobile();
    }

    public function isTabletDevice()
    {
        return $this->mobileDetect->isTablet();
    }

    public function getDeviceCategory()
    {
        // Tablet detection must be first
        if ($this->isTabletDevice()) {
            return 'Tablet';
        } elseif ($this->isMobileDevice()) {
            return 'Mobile';
        } else {
            return 'Desktop';
        }
    }

    public function getTrackingId()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            if ($cookie = $request->cookies->get(SoSure::SOSURE_TRACKING_COOKIE_NAME)) {
                $this->logger->debug(sprintf('Tracking cookie: %s', urldecode($cookie)));

                return urldecode($cookie);
            } elseif ($trackingId = $this->getSession(SoSure::SOSURE_TRACKING_SESSION_NAME)) {
                $this->logger->debug(sprintf('Session tracking: %s', $trackingId));

                return $trackingId;
            } else {
                $uuid4 = Uuid::uuid4();
                if ($session = $this->getSession()) {
                    $trackingId = $uuid4->toString();
                    $session->set(SoSure::SOSURE_TRACKING_SESSION_NAME, $trackingId);
                    $this->logger->debug(sprintf('Session tracking init: %s', $trackingId));

                    return $trackingId;
                }
            }
        }

        return null;
    }

    public function isSoSureEmployee()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            if ($cookie = $request->cookies->get(SoSure::SOSURE_EMPLOYEE_COOKIE_NAME)) {
                return urldecode($cookie) === $this->adminCookieValue;
            }
        }

        return false;
    }

    public function isExcludedAnalyticsIp()
    {
        if ($clientIp = $this->getClientIp()) {
            return IpUtils::checkIp($clientIp, [
                '62.253.24.186', // rwe
                '213.86.221.35', // wework
                '80.169.94.194', // rwe shoreditch
                '167.98.14.60', // coccoon
                '217.158.0.52', // davies
                // '10.0.2.2', // for debugging - vagrant
            ]);
        }

        return false;
    }
}
