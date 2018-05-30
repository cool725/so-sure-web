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
        $client = new \Google_Client(['client_id' => "1062115475688-0ngn2v5s4bh7qtecchbgc6gn2lrbiejs.apps.googleusercontent.com"]);
        //$client->setApplicationName($this->googleAppName);
        //$client->setDeveloperKey($this->googleApiKey);

        /*
        $payload = 'https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=' . $token;
        $json = file_get_contents($payload);

        $this->logger->error('googleService payload', ['payload' => $json]);
        if ($json) {
            $userInfoArray = json_decode($json, true);
            $googleEmail = $userInfoArray['email'];
            $googleId = $userInfoArray['sub'];
            return $googleId;
        }
        */

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
