<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class PremiumService
{
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
