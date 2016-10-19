<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class MiscTwigExtension extends \Twig_Extension
{
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

    public function jsonDecode($json)
    {
        return json_decode($json);
    }

    public function getName()
    {
        return 'app_twig_misc';
    }
}
