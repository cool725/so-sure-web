<?php

namespace AppBundle\Listener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class PosIpListener
{
    /** @var array $posIps */
    private $posIps;

    public function __construct(array $posIps = array())
    {
        $this->posIps = $posIps;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $request  = $event->getRequest();

        if (mb_strpos($request->getRequestUri(), '/pos/') !== 0 && in_array($request->getClientIp(), $this->posIps)) {
            $event->setResponse(new Response('', 403));
        }
    }
}