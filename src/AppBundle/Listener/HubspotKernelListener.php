<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Classes\SoSure;

class HubspotKernelListener
{
    protected $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $request  = $event->getRequest();

        $disallowedMethod = in_array($request->getMethod(), [
            Request::METHOD_POST,
            Request::METHOD_DELETE,
            Request::METHOD_PUT,
            Request::METHOD_PATCH
        ]);
        $isHubspotRequest = mb_stripos($request->headers->get('User-Agent'), 'HubSpot') !== false;

        // Hubspot crawler is re-issuing requests.
        // This retries our judo pay transactions and generates (non-important) errors
        // but in general, this is problematic with any POST, PUT, DELETE options
        if ($disallowedMethod && $isHubspotRequest) {
            throw new AccessDeniedHttpException(sprintf(
                'Hubspot crawler should not be making %s requests',
                $request->getMethod()
            ));
        }
    }
}
