<?php
namespace AppBundle\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class RouterService
{
    /** @var RouterInterface */
    protected $router;

    /** @var string */
    protected $baseUrl;

    /**
     * @param RouterInterface $router
     * @param string          $baseUrl
     */
    public function __construct(
        RouterInterface $router,
        $baseUrl
    ) {
        $this->router = $router;
        $this->baseUrl = $baseUrl;
    }

    /**
     * @return RouterInterface
     */
    public function getRouter()
    {
        return $this->router;
    }

    public function generate($route, $params)
    {
        return sprintf(
            "%s",
            $this->router->generate($route, $params)
        );
    }

    public function generateUrl($route, $params)
    {
        return sprintf(
            "%s%s",
            $this->baseUrl,
            $this->router->generate($route, $params)
        );
    }
}
