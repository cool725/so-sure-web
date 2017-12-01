<?php
namespace AppBundle\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RouterService
{
    protected $router;

    /** @var string */
    protected $baseUrl;

    /**
     * @param        $router
     * @param string $baseUrl
     */
    public function __construct(
        $router,
        $baseUrl
    ) {
        $this->router = $router;
        $this->baseUrl = $baseUrl;
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
