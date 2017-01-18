<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Mixpanel;

class MixpanelService
{
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
            $this->mixpanel->identify($user->getId());
            $this->mixpanel->people->set($user->getId(), [
                '$first_name'       => $user->getFirstName(),
                '$last_name'        => $user->getLastName(),
                '$email'            => $user->getEmail(),
                '$phone'            => $user->getMobileNumber(),
            ]);
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
        $this->mixpanel->track($event, $properties);
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
