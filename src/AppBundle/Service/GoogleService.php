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
    protected $clientId;

    /**
     * @param LoggerInterface $logger
     * @param string          $clientId
     */
    public function __construct(
        LoggerInterface $logger,
        $clientId
    ) {
        $this->logger = $logger;
        $this->clientId = $clientId;
    }

    /**
     * @param string $token
     *
     * @return string
     */
    public function getUserIdFromToken($token)
    {
        $client = new \Google_Client(['client_id' => $this->clientId]);
        $payload = $client->verifyIdToken($token);
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
            return $this->getUserIdFromToken($token) == $id;
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
