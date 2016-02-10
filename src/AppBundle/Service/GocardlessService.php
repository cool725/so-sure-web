<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;

class GocardlessService
{
   /** @var LoggerInterface */
   protected $logger;

   /** @var Client */
   protected $client;

    /**
     * @param LoggerInterface $logger
     * @param string $accessToken
     */
    public function __construct(LoggerInterface $logger, $accessToken)
    {
       $this->logger = $logger;
       $client = new Client([
            'access_token' => $accessToken,
            'environment' => \GoCardlessPro\Environment::SANDBOX
        ]);
    }
    
    
}