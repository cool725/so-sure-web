<?php
namespace AppBundle\Service;

use AppBundle\Document\Attribution;
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
    const DEVICE_OS_IPHONE = 'iPhone';
    const DEVICE_OS_IOS = 'iOS';

    const UTM_CAMPAIGN = 'campaign';
    const UTM_MEDIUM = 'medium';
    const UTM_SOURCE = 'source';
    const UTM_TERM = 'term';
    const UTM_CONTENT = 'content';

    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    /** @var TokenStorage  */
    protected $tokenStorage;
    protected $adminCookieValue;
    protected $mobileDetect;
    protected $environment;
    protected $excludedIps;

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
     * @param string          $excludedIps
     */
    public function __construct(
        RequestStack $requestStack,
        LoggerInterface $logger,
        TokenStorage $tokenStorage,
        $adminCookieValue,
        $environment,
        $excludedIps
    ) {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->tokenStorage = $tokenStorage;
        $this->adminCookieValue = $adminCookieValue;
        $this->environment = $environment;
        if ($request = $this->requestStack->getCurrentRequest()) {
            $this->mobileDetect = new Mobile_Detect($request->server->all());
        }
        $this->excludedIps = $excludedIps;
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
        if ($request = $this->requestStack->getCurrentRequest()) {
            $session = $request->getSession();
            if ($session && $session->isStarted()) {
                if ($var) {
                    return $session->get($var);
                } else {
                    return $session;
                }
            }
        }

        return null;
    }

    /**
     * @return Attribution
     * @throws \UAParser\Exception\FileNotFoundException
     */
    public function getAttribution()
    {
        $attribution = new Attribution();

        $utm = $this->getUtm();
        if (isset($utm[self::UTM_CAMPAIGN])) {
            $attribution->setCampaignName($utm[self::UTM_CAMPAIGN]);
        }
        if (isset($utm[self::UTM_SOURCE])) {
            $attribution->setCampaignSource($utm[self::UTM_SOURCE]);
        }
        if (isset($utm[self::UTM_MEDIUM])) {
            $attribution->setCampaignMedium($utm[self::UTM_MEDIUM]);
        }
        if (isset($utm[self::UTM_CONTENT])) {
            $attribution->setCampaignContent($utm[self::UTM_CONTENT]);
        }
        if (isset($utm[self::UTM_TERM])) {
            $attribution->setCampaignTerm($utm[self::UTM_TERM]);
        }

        $deviceCategory = null;
        $deviceOS = null;
        if ($userAgent = $this->getUserAgent()) {
            $parser = Parser::create();
            $userAgentDetails = $parser->parse($userAgent);
            $deviceCategory = $this->getDeviceCategory();
            $deviceOS = $this->getDeviceOS();
        }
        $attribution->setDeviceCategory($deviceCategory);
        $attribution->setDeviceOS($deviceOS);

        $referer = $this->getReferer();
        if ($referer) {
            $refererDomain = parse_url($referer, PHP_URL_HOST);
            $currentDomain = parse_url($this->getUri(), PHP_URL_HOST);
            if (mb_strtolower($refererDomain) != mb_strtolower($currentDomain)) {
                $attribution->setReferer($referer);
            }
        }

        return $attribution;
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

    public function hasClaimsRole()
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }

        return $user->hasClaimsRole();
    }

    public function hasEmployeeRole()
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }

        return $user->hasEmployeeRole();
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

    public function isDeviceOsAndroid($userAgent = null)
    {
        return in_array($this->getDeviceOS($userAgent), [
            self::DEVICE_OS_ANDROID,
        ]);
    }

    public function isDeviceOsIOS($userAgent = null)
    {
        return in_array($this->getDeviceOS($userAgent), [
            self::DEVICE_OS_IPHONE,
            self::DEVICE_OS_IOS,
        ]);
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

        if ($this->isExcludedPreviewPrefetch()) {
            return true;
        }

        if ($this->environment == 'prod' &&
            ($this->isSoSureEmployee() ||
            $this->isExcludedAnalyticsIp())) {
            return true;
        }

        return false;
    }

    public function isExcludedAnalyticsIp($clientIp = null)
    {
        if (!$clientIp) {
            $clientIp = $this->getClientIp();
        }

        if (!$clientIp) {
            return false;
        }

        return IpUtils::checkIp($clientIp, $this->excludedIps);
    }

    public function getAllXHeaders()
    {
        $headers = [];
        if ($request = $this->requestStack->getCurrentRequest()) {
            foreach ($request->headers->all() as $key => $value) {
                if (mb_stripos($key, 'x-') === 0) {
                    $headers[$key] = $value;
                }
            }
        }

        return $headers;
    }

    public function isExcludedPreviewPrefetch()
    {
        if ($request = $this->requestStack->getCurrentRequest()) {
            $purpose = $request->headers->get('x-purpose');
            if ($purpose == 'preview') {
                return true;
            }

            $moz = $request->headers->get('x-moz');
            if ($moz == 'prefetch') {
                return true;
            }
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
        if (mb_stripos($userAgentDetails->ua->family, 'crawler') !== false) {
            return true;
        }
        //if (mb_stripos($userAgentDetails->ua->family, 'facebook') !== false) {
        //    return true;
        //}

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
            'YandexVideoParser',
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
        if (mb_stripos($userAgent, 'Google-Apps-Script') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'HappyApps') !== false) {
            return true;
        }
        if (mb_stripos($userAgent, 'Branch Metrics') !== false) {
            return true;
        }

        // crawler on 7/3/19 - unlikely to be a legit user as we don't support IE 8
        if ($userAgent == 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0)') {
            return true;
        }

        return false;
    }
}
