<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class PremiumService
{
   /** @var LoggerInterface */
   protected $logger;

    /**
     * @param LoggerInterface $logger
     * @param string $apikey
     * @param string $list
     */
    public function __construct(LoggerInterface $logger)
    {
       $this->logger = $logger;
    }    
}
