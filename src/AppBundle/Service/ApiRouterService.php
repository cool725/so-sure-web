<?php
namespace AppBundle\Service;

class ApiRouterService
{
    protected $router;
    protected $listenPort;
    protected $httpPort;
    protected $httpsPort;

    public function __construct($router, $listenPort, $httpPort, $httpsPort)
    {
        $this->router = $router;
        $this->listenPort = $listenPort;
        $this->httpPort = $httpPort;
        $this->httpsPort = $httpsPort;
    }

    public function getRouter()
    {
        $this->overridePorts();
        return $this->router;
    }

    protected function overridePorts()
    {
        $context = $this->router->getContext();
        if ($context->getHttpPort() == $this->listenPort) {
            $context->setHttpPort($this->httpPort);
        }
        if ($context->getHttpsPort() == $this->listenPort) {
            $context->setHttpsPort($this->httpsPort);
        }
    }
}
