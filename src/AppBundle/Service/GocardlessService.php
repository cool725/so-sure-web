<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use \GoCardlessPro\Environment;

class GocardlessService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var Client */
    protected $client;

    /**
     * @param LoggerInterface $logger
     * @param string          $accessToken
     * @param boolean         $prod
     */
    public function __construct(LoggerInterface $logger, $accessToken, $prod)
    {
        $this->logger = $logger;
        $client = new Client([
            'access_token' => $accessToken,
            'environment' => $prod ? Environment::LIVE : Environment::SANDBOX
        ]);
    }
}
