<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\User;
use Mixpanel;
use UAParser\Parser;

class MixpanelService
{
    const BUY_BUTTON_CLICKED = 'Click on the Buy Now Button';
    const IMEI_ENTERED = 'Enter IMEI Number';
    const PURCHASE_POLICY = 'Purchase Policy';
    const INVITE = 'Invite someone';
    const ACCEPT_CONNECTION = 'Accept Connection';
    const HOME_PAGE = 'Home Page';
    const QUOTE_PAGE = 'Quote Page';
    const RECEIVE_DETAILS = 'Receive Personal Details';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $redis;

    /** @var Mixpanel */
    protected $mixpanel;

    /** @var RequestService */
    protected $requestService;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $redis
     * @param Mixpanel        $mixpanel
     * @param RequestService  $requestService
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $redis,
        Mixpanel $mixpanel,
        RequestService $requestService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->mixpanel = $mixpanel;
        $this->requestService = $requestService;
    }

    public function track($event, array $properties = null)
    {
        return $this->trackAll($event, $properties);
    }

    public function trackWithUtm($event, array $properties = null)
    {
        return $this->trackAll($event, $properties, null, true, true);
    }

    public function trackAll($event, array $properties = null, $user = null, $addUtm = false, $addUrl = false)
    {
        if (!$user) {
            $user = $this->requestService->getUser();
        }
        if ($user) {
            $userData = ['$email' => $user->getEmail()];
            if ($user->getFirstName()) {
                $userData['$first_name'] = $user->getFirstName();
            }
            if ($user->getLastName()) {
                $userData['$last_name'] = $user->getLastName();
            }
            if ($user->getMobileNumber()) {
                $userData['$phone'] = $user->getMobileNumber();
            }
            if ($user->getBirthday()) {
                $userData['Date of Birth'] = $user->getBirthday()->format(\DateTime::ATOM);
            }
            if ($user->getBillingAddress()) {
                $userData['Billing Address'] = $user->getBillingAddress()->__toString();
            }
            if ($policy = $user->getCurrentPolicy()) {
                if ($phone = $policy->getPhone()) {
                    $userData['Device'] = $phone->__toString();
                }
                if ($premium = $policy->getPremium()) {
                    $userData['Monthly Cost'] = $premium->getMonthlyPremiumPrice();
                }
                if ($plan = $policy->getPremiumPlan()) {
                    $userData['Payment Option'] = $plan;
                }
                $userData['Number of Connections'] = count($policy->getConnections());
                $userData['Reward Pot Value'] = $policy->getPotValue();
            }
            $this->mixpanel->identify($user->getId());
            $this->mixpanel->people->set($user->getId(), $userData);
            //$this->logger->debug(sprintf('User %s details %s', $user->getId(), json_encode($userData)));
        } else {
            if ($trackingId = $this->requestService->getTrackingId()) {
                $this->mixpanel->identify($trackingId);
            }
        }

        if (!$properties) {
            $properties = [];
        }
        if ($addUtm) {
            $properties = array_merge($properties, $this->transformUtm());
        }
        if ($addUrl) {
            $properties = array_merge($properties, $this->transformUrl());
        }
        if ($ip = $this->requestService->getClientIp()) {
            $properties['ip'] = $ip;
        }
        if ($userAgent = $this->requestService->getUserAgent()) {
            $parser = Parser::create();
            $userAgentDetails = $parser->parse($userAgent);
            $properties['$browser'] = $userAgentDetails->ua->family;
            $properties['$browser_version'] = $userAgentDetails->ua->toVersion();
            $properties['User Agent'] = $userAgent;
        }
        $this->mixpanel->track($event, $properties);
    }

    public function register(User $user = null, $trackingId = null)
    {
        if (!$trackingId) {
            $trackingId = $this->requestService->getTrackingId();
        }
        if ($user && $trackingId) {
            $this->logger->debug(sprintf(
                'Alias user %s to tracking id: %s',
                $user ? $user->getId() : 'unknown',
                $trackingId
            ));
            $this->mixpanel->createAlias($trackingId, $user->getId());
        } else {
            $this->logger->warning(sprintf(
                'Failed to register user %s id: %s',
                $user ? $user->getId() : 'unknown',
                $trackingId
            ));
        }
    }

    private function transformUrl()
    {
        $uri = $this->requestService->getUri();
        if (!$uri) {
            return [];
        }

        return ['URL' => $uri];
    }

    private function transformUtm()
    {
        $utm = $this->requestService->getUtm();
        if (!$utm) {
            return [];
        }

        $transform = [];
        if (isset($utm['source']) && $utm['source']) {
            $transform['Campaign Source'] = $utm['source'];
        }
        if (isset($utm['medium']) && $utm['medium']) {
            $transform['Campaign Medium'] = $utm['medium'];
        }
        if (isset($utm['campaign']) && $utm['campaign']) {
            $transform['Campaign Name'] = $utm['campaign'];
        }
        if (isset($utm['term']) && $utm['term']) {
            $transform['Campaign Term'] = $utm['term'];
        }
        if (isset($utm['content']) && $utm['content']) {
            $transform['Campaign Content'] = $utm['content'];
        }

        return $transform;
    }
}
