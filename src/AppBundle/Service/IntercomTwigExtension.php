<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class IntercomTwigExtension extends \Twig_Extension
{
    /** @var IntercomService */
    protected $intercom;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param IntercomService $intercom
     * @param LoggerInterface $logger
     */
    public function __construct(
        IntercomService $intercom,
        LoggerInterface $logger
    ) {
        $this->intercom = $intercom;
        $this->logger = $logger;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('intercom', array($this, 'intercom')),
        );
    }

    public function intercom($user)
    {
        return $this->intercom->getUserHash($user, IntercomService::SECURE_WEB);
    }

    public function getName()
    {
        return 'app_twig_intercom';
    }
}
