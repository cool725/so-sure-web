<?php
namespace AppBundle\Service;

use Google\Client;
use AppBundle\Document\User;
use Psr\Log\LoggerInterface;

class GoogleService
{

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $googleAppName;

    /** @var string */
    protected $googleApiKey;

    /** @var string */
    protected $clientId;

    /**
     * @param LoggerInterface $logger
     * @param string          $googleAppName
     * @param string          $googleApiKey
     * @param string          $clientId
     */
    public function __construct(
        LoggerInterface $logger,
        $googleAppName,
        $googleApiKey,
        $clientId
    ) {
        $this->logger = $logger;
        $this->googleAppName = $googleAppName;
        $this->googleApiKey = $googleApiKey;
        $this->clientId = $clientId;
    }

    /**
     * @param string $token
     *
     * @return string|null
     */
    public function getUserIdFromToken($token)
    {
        var_dump("getUserIdFromToken");
        $client = new \Google_Client(['client_id' => "1062115475688-k4p91u0ju8kss69gb8g59r1e45vit38j.apps.googleusercontent.com"]);
        $client->setApplicationName($this->googleAppName);
        $client->setDeveloperKey($this->googleApiKey);
        var_dump("getUserIdFromToken client");

        $payload = $client->verifyIdToken($token);
        $this->logger->error('googleService payload', ['payload' => $payload]);
        if ($payload) {
            var_dump($payload);
            $userid = $payload['sub'];
            return $userid;
        }

        var_dump("getUserIdFromToken failed");

        return null;
    }

    /**
     * Ensure that token is valid and matches the expected user
     */
    public function validateToken(User $user, $token)
    {
        var_dump($user->getGoogleId());
        return $this->validateTokenId($user->getGoogleId(), $token);
    }

    /**
     * Ensure that token is valid and matches the expected id
     */
    public function validateTokenId($id, $token)
    {
        $idFromToken = $this->getUserIdFromToken($token);
        try {
            
            $this->logger->error('googleService ', ['id' => $id, 'idFromToken' => $idFromToken]);
            return  $idFromToken == $id;
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Unable to validate google token for google id %s, Ex: %s',
                $id,
                $e->getMessage()
            ));

            return false;
        }
    }
}
