<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class RequestTwigExtension extends \Twig_Extension
{
    /** @var RequestService */
    protected $requestService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param RequestService  $requestService
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestService $requestService,
        LoggerInterface $logger
    ) {
        $this->requestService = $requestService;
        $this->logger = $logger;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('device_category', [$this, 'getDeviceCategory']),
        );
    }

    public function getDeviceCategory()
    {
        return $this->requestService->getDeviceCategory();
    }

    public function getName()
    {
        return 'app_twig_request';
    }
}
