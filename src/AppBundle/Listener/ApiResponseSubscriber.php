<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Event\InvitationEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponseSubscriber implements EventSubscriberInterface
{
    const KEY_HASH_PATH = 'apiresponse:paths';
    const KEY_RANDOM_FAILURE = 'apiresponse:random';
    protected $redis;

    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();
        if (mb_stripos($request->getPathInfo(), '/api/v1/') === 0) {
            if (($errorCode = $this->redis->hget(self::KEY_HASH_PATH, $request->getPathInfo())) !== null) {
                $event->setResponse($this->generateJsonError($errorCode));
            } elseif (($random = $this->redis->get(self::KEY_RANDOM_FAILURE)) !== null) {
                if (rand(0, 100) <= $random) {
                    $event->setResponse($this->generateJsonError(500));
                }
            }
        }
    }

    private function generateJsonError($errorCode)
    {
        return new JsonResponse([
            'code' => 1,
            'description' => 'ApiResponseSubscriber'
        ], $errorCode);
    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::RESPONSE => 'onKernelResponse',
        );
    }
}
