<?php
namespace App\Tests\Traits;

use App\Oauth2Scopes;
use AppBundle\Document\Oauth\AccessToken;
use AppBundle\Document\Oauth\Client;
use AppBundle\Document\User;
use Doctrine\Common\Persistence\ObjectManager;

trait Oauth
{
    /**
     * Make an OauthClient with specific id & secret. Used to test logging in
     */
    protected function newOauth2Client(
        ObjectManager $manager,
        string $clientIdKey,
        string $clientIdRandom = null,
        string $clientSecret = null,
        array $grantTypes = [],
        array $redirectUrls = []
    ): Client {
        $client = new Client();

        // The 'CLIENT_ID' is (*_KEY . '_' . *_RANDOM)
        $this->setClientId($client, $clientIdKey);
        $client->setRandomId($clientIdRandom ?? $this->strRand());

        $client->setSecret($clientSecret ?? $this->strRand());

        $client->setAllowedGrantTypes(array_merge($client->getAllowedGrantTypes(), $grantTypes));
        $client->setRedirectUris($redirectUrls);

        $manager->persist($client);

        return $client;
    }

    private function strRand(int $length = 32)
    {
        $length = ($length < 4) ? 4 : $length;
        return bin2hex(random_bytes(($length - ($length % 2)) / 2));
    }

    /**
     * Make the bearer-token directly for a given user (by email)
     */
    protected function newOauth2AccessToken(ObjectManager $manager, Client $client, User $user, string $token)
    {
        $accessToken = new AccessToken();
        $accessToken->setClient($client);
        $accessToken->setUser($user);
        $accessToken->setToken($token);
        $accessToken->setExpiresAt(PHP_INT_MAX);
        $accessToken->setScope(Oauth2Scopes::USER_STARLING_SUMMARY
            . ' ' . Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY);

        $manager->persist($accessToken);
        $manager->flush();
    }

    /**
     * Force-access to the protected $id to set.
     *
     * Must be a valid mongoId (as a string)
     */
    private function setClientId(Client $client, string $_id)
    {
        $class = new \ReflectionClass(Client::class);
        $property = $class->getProperty('id');
        $property->setAccessible(true);

        $property->setValue($client, $_id);
    }
}
