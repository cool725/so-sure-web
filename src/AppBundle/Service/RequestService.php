<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Document\User;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\IpUtils;
use Mobile_Detect;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use UAParser\Parser;

class RequestService
{
    // Sync with Documents\Attribution::deviceCategory
    const DEVICE_CATEGORY_MOBILE = 'Mobile';
    const DEVICE_CATEGORY_TABLET = 'Tablet';
    const DEVICE_CATEGORY_DESKTOP = 'Desktop';

    const DEVICE_OS_ANDROID = 'Android';

    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    /** @var TokenStorage  */
    protected $tokenStorage;
    protected $adminCookieValue;
    protected $mobileDetect;
    protected $environment;

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     * @param TokenStorage    $tokenStorage
     * @param string          $adminCookieValue
     * @param string          $environment
     */
    public function __construct(
        RequestStack $requestStack,
        LoggerInterface $logger,
        TokenStorage $tokenStorage,
        $adminCookieValue,
        $environment
    ) {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->tokenStorage = $tokenStorage;
        $this->adminCookieValue = $adminCookieValue;
        $this->environment = $environment;
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

    /**
     * @return User|null
     */
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
        if ($this->mobileDetect) {
            return $this->mobileDetect->isMobile();
        }

        return null;
    }

    public function isTabletDevice()
    {
        if ($this->mobileDetect) {
            return $this->mobileDetect->isTablet();
        }

        return null;
    }

    public function getDeviceCategory()
    {
        // Sync with Documents\Attribution::deviceCategory
        // Tablet detection must be first
        if ($this->isTabletDevice()) {
            return self::DEVICE_CATEGORY_TABLET;
        } elseif ($this->isMobileDevice()) {
            return self::DEVICE_CATEGORY_MOBILE;
        } else {
            return self::DEVICE_CATEGORY_DESKTOP;
        }
    }

    public function getDeviceOS($userAgent = null)
    {
        if (!$userAgent) {
            $userAgent = $this->getUserAgent();
        }

        if (!$userAgent) {
            return null;
        }

        $parser = Parser::create();
        $userAgentDetails = $parser->parse($userAgent);

        return $userAgentDetails->os->family;
        /*
        if ($this->mobileDetect) {
            foreach (Mobile_Detect::getOperatingSystems() as $operatingSystem) {
                if ($this->mobileDetect->is($operatingSystem)) {
                    return $operatingSystem;
                }
            }
        }

        return null;
        */
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

    public function isExcludedAnalytics()
    {
        if ($this->environment == 'test') {
            return true;
        }

        if ($this->isExcludedAnalyticsUserAgent()) {
            return true;
        }

        if ($this->environment == 'prod' &&
            ($this->isSoSureEmployee() ||
            $this->isExcludedAnalyticsIp())) {
            return true;
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

    public function isExcludedAnalyticsUserAgent($userAgent = null)
    {
        if (!$userAgent) {
            $userAgent = $this->getUserAgent();
        }

        if (!$userAgent) {
            return false;
        }

        $parser = Parser::create();
        $userAgentDetails = $parser->parse($userAgent);

        if (mb_stripos($userAgentDetails->ua->family, 'bot') !== false) {
            return true;
        }
        if (mb_stripos($userAgentDetails->ua->family, 'spider') !== false) {
            return true;
        }
        if (mb_stripos($userAgentDetails->ua->family, 'crawler') !== false) {
            return true;
        }

        // exclude bots from tracking
        if (in_array($userAgentDetails->ua->family, [
            'PhantomJS',
            'Yahoo! Slurp',
            'Apache-HttpClient',
            'Java',
            'Python Requests',
            'Python-urllib',
            'Scrapy',
            'Google',
            'ia_archiver',
            'SimplePie',
        ])) {
            return true;
        }

        if (mb_stripos($userAgent, 'StatusCake') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'okhttp') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'curl') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'ips-agent') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'ScoutJet') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'Go-http-client') !== false) {
            return true;
        }

        return false;
    }
}
