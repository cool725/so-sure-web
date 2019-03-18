<?php

namespace AppBundle\Listener;

use AppBundle\Classes\Premium;
use AppBundle\Document\Attribution;
use AppBundle\Document\PhonePolicy;
use AppBundle\Event\PolicyEvent;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RequestService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Document\Policy;

class GoCompareListener
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $environment;

    /**
     * @param LoggerInterface $logger
     * @param string          $environment
     */
    public function __construct(
        LoggerInterface $logger,
        $environment
    ) {
        $this->logger = $logger;
        $this->environment = $environment;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        if ($this->environment != 'prod') {
            return;
        }

        $user = $event->getPolicy()->getUser();
        if ($user->getAttribution() && $user->getAttribution()->getGoCompareQuote()) {
            /** @var PhonePolicy $policy */
            $policy = $event->getPolicy();
            return $this->notifyGoCompare($policy);
        }

        return false;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        if ($this->environment != 'prod') {
            return;
        }

        $user = $event->getPolicy()->getUser();
        /** @var PhonePolicy $policy */
        $policy = $event->getPolicy();
        if ($user->getAttribution() && $user->getAttribution()->getGoCompareQuote() && $policy->isCooloffCancelled()) {
            return $this->notifyGoCompare($policy);
        }

        return false;
    }

    public function notifyGoCompare(PhonePolicy $policy)
    {
        $url = $this->getGoCompareTrackingUrl($policy);
        try {
            $client = new Client();
            $res = $client->request('GET', $url);
            $body = (string) $res->getBody();
            $this->logger->debug(sprintf('Received %s from go compare', $body));

            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error from go compare'), ['exception' => $e]);
        }

        return false;
    }

    public function getGoCompareTrackingUrl(PhonePolicy $policy)
    {
        $attribution = $policy->getUser()->getAttribution();
        // 0 = Not a cancellation; 1 = Cancellation; This will cancel out the sale of the same quote ID.
        $cancellationParam = $policy->isCooloffCancelled() ? 1 : 0;
        // Your Premium ID’s are as follows; Monthly – 3; Annual – 2
        $premiumTypeParam = $policy->getPremiumPlan() == Policy::PLAN_MONTHLY ? 3 : 2;
        // Was the sale completed on a mobile device 0/1
        $mobileParam = $attribution->getDeviceCategory() == RequestService::DEVICE_CATEGORY_MOBILE ? 1 : 0;

        $providerParam = 0;
        $sourceParam = 0;
        if ($attribution->getCampaignSource() == 'PYG') {
            // PYG - 70; Go Compare - 180; MSM - 179
            $providerParam = 70;
            // 1 – Moneysupermarket; 3 – Compare The Market; 2 – Go Compare; 4 – Confused; 5 – Protect Your
            $sourceParam = 5;
        } elseif ($attribution->getCampaignSource() == 'GoCompare') {
            // PYG - 70; Go Compare - 180; MSM - 179
            $providerParam = 180;
            // 1 – Moneysupermarket; 3 – Compare The Market; 2 – Go Compare; 4 – Confused; 5 – Protect Your
            $sourceParam = 2;
        } elseif ($attribution->getCampaignSource() == 'MoneySupermarket') {
            // PYG - 70; Go Compare - 180; MSM - 179
            $providerParam = 179;
            // 1 – Moneysupermarket; 3 – Compare The Market; 2 – Go Compare; 4 – Confused; 5 – Protect Your
            $sourceParam = 1;
        }

        // @codingStandardsIgnoreStart
        return sprintf(
            'https://salesdb.comparisoncreator.com/online-sale/?quote=%s&reference=%s&cancellation=%d&premium=%0.2f&premium_type=%s&product_name=%s&provider_id=%d&source=%d&mobile=%d',
            $attribution->getGoCompareQuote(),
            $policy->getId(),
            $cancellationParam,
            $policy->getPremiumInstallmentPrice(true),
            $premiumTypeParam,
            urlencode($policy->getPhone()),
            $providerParam,
            $sourceParam,
            $mobileParam
        );
        // @codingStandardsIgnoreEnd
    }
}
