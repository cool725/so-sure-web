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
    protected $googleClientId;

    /**
     * @param LoggerInterface $logger
     * @param string          $googleClientId
     */
    public function __construct(
        LoggerInterface $logger,
        $googleClientId
    ) {
        $this->logger = $logger;
        $this->googleClientId = $googleClientId;
    }

    /**
     * @param string $token
     *
     * @return string|null
     */
    public function getUserIdFromToken($token)
    {
        $client = new \Google_Client(['client_id' => $this->googleClientId]);

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
            $idFromToken = $this->getUserIdFromToken($token);
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
