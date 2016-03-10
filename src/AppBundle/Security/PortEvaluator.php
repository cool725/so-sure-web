<?php

namespace AppBundle\Security;

use Symfony\Component\DependencyInjection\ContainerInterface;
use JMS\DiExtraBundle\Annotation as DI;

/** @DI\Service */
class PortEvaluator
{
    private $container;

    /**
     * @DI\InjectParams({
     *     "container" = @DI\Inject("service_container"),
     * })
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /** @DI\SecurityFunction("isApiPort") */
    public function isApiPort($apiPort)
    {
        // As we're using trusted proxies, the request port is overwritten, so need to access the raw port
        // See http://php.net/manual/en/reserved.variables.server.php under SERVER_PORT
        // See http://stackoverflow.com/questions/6474783/which-server-variables-are-safe
        // Requires UseCanonicalName On & UseCanonicalPhysicalPort On in apache in order to be trusted
        $defaultPort = $this->container->getParameter('kernel.environment') == 'test' ? 8080 : 80;
        $serverPort = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : $defaultPort;
        $logger = $this->container->get('logger');
        $logger->debug(sprintf("SERVER PORT: %s Expected: %s", $serverPort, $apiPort));

        return intval($serverPort) === intval($apiPort);
    }
}
