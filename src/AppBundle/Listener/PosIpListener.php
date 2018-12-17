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

        if (in_array($request->getClientIp(), $this->posIps) && mb_strpos($request->getRequestUri(), '/pos/') !== 0) {
            $event->setResponse(new Response('', 403));
        }
    }
}
