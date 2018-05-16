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
        $client = new \Google_Client(['client_id' => "1062115475688-lm82l8p6ckr2bp7mus2q7q7mkiu01q4f.apps.googleusercontent.com");
        $client->setApplicationName($this->googleAppName);
        $client->setDeveloperKey($this->googleApiKey);
        $client->setScopes('email profile');

        $payload = $client->verifyIdToken($token);
        $this->logger->error('googleService payload', ['payload' => $payload]);
        if ($payload) {
            $userid = $payload['sub'];
            return $userid;
        }

        return null;
    }

    /**
     * Ensure that token is valid and matches the expected user
     */
    public function validateToken(User $user, $token)
    {
        return $this->validateTokenId($user->getGoogleId(), $token);
    }

    /**
     * Ensure that token is valid and matches the expected id
     */
    public function validateTokenId($id, $token)
    {
        try {
            $idFromToken = $this->getUserIdFromToken($token);        
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
