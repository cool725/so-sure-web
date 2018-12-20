<?php
namespace AppBundle\Service;

use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Router;

class MiscTwigExtension extends \Twig_Extension
{
    use CurrencyTrait;
    use ImeiTrait;
    use DateTrait;

    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Router */
    protected $router;

    /**
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     * @param Router          $router
     */
    public function __construct(
        RequestStack $requestStack,
        LoggerInterface $logger,
        Router $router
    ) {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->router = $router;
    }

    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('json_decode', [$this, 'jsonDecode']),
        );
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('equal_to_two_dp', [$this, 'areEqualToTwoDp']),
            new \Twig_SimpleFunction('path_info', [$this, 'pathInfo']),
            new \Twig_SimpleFunction('random_imei', [$this, 'generateRandomImei']),
            new \Twig_SimpleFunction('random_serial', [$this, 'generateRandomAppleSerialNumber']),
            new \Twig_SimpleFunction('route_exists', [$this, 'routeExists']),
            new \Twig_SimpleFunction('next_business_day_historical', [$this, 'getNextBusinessDayByString']),
        );
    }

    public function jsonDecode($json)
    {
        return json_decode($json);
    }

    public function pathInfo($path)
    {
        return pathinfo($path);
    }

    public function routeExists($route, $params)
    {
        try {
            $this->router->generate($route, $params);

            return true;
        } catch (RouteNotFoundException $e) {
            return false;
        }
    }

    public function getNextBusinessDayByString($date)
    {
        return $this->getNextBusinessDayHistorical(\DateTime::createFromFormat('Y-m-d', $date));
    }

    public function getName()
    {
        return 'app_twig_misc';
    }
}
