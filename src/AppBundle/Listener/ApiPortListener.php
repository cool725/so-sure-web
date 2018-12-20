<?php

namespace AppBundle\Listener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class ApiPortListener
{
    /** @var LoggerInterface */
    protected $logger;
    protected $environment;

    public function __construct(LoggerInterface $logger, $environment)
    {
        $this->logger = $logger;
        $this->environment = $environment;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $apiPort = '8080';
        $request  = $event->getRequest();

        // As we're using trusted proxies, the request port is overwritten, so need to access the raw port
        // See http://php.net/manual/en/reserved.variables.server.php under SERVER_PORT
        // See http://stackoverflow.com/questions/6474783/which-server-variables-are-safe
        // Requires UseCanonicalName On & UseCanonicalPhysicalPort On in apache in order to be trusted
        $defaultPort = $this->environment == 'test' ? 8080 : 80;
        $serverPort = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : $defaultPort;
        $this->logger->debug(sprintf("SERVER PORT for %s: %s Expected: %s", $this->environment, $serverPort, $apiPort));

        if (mb_strpos($request->getRequestUri(), '/api/') === 0 && intval($serverPort) != intval($apiPort)) {
            $event->setResponse(new Response('', 403));
        }
    }
}
