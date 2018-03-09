<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;

class MiscTwigExtension extends \Twig_Extension
{
    use CurrencyTrait;

    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
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

    public function getName()
    {
        return 'app_twig_misc';
    }
}
