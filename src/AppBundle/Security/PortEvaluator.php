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
    public function isApiPort()
    {
        $this->container->get('logger')->warning(sprintf("SERVER PORT: %s", $_SERVER['SERVER_PORT']));

        return true;
    }
}