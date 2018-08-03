<?php
namespace App\Tests\Traits;

use App\Oauth2Scopes;
use AppBundle\Document\Oauth\AccessToken;
use AppBundle\Document\Oauth\Client;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;

trait Oauth
{
    /**
     * Make an OauthClient with specific id & secret. Used to test logging in
     */
    protected function newOauth2Client(
        DocumentManager $manager,
        array $grantTypes,
        array $redirectUrls,
        $clientIdKey,
        $clientIdRandom,
        $clientSecret
    ): Client {
        $grantTypes = array_merge($grantTypes, []);
        $redirectUrls = array_merge($redirectUrls, [ '/' ]);  // a 'KNOWN_CLIENT_CALLBACK_URL'

        $client = new Client();

        // The 'CLIENT_ID' is (*_KEY . '_' . *_RANDOM)
        $this->setClientId($client, $clientIdKey);
        $client->setRandomId($clientIdRandom);

        $client->setSecret($clientSecret);
        $client->setAllowedGrantTypes(array_merge($client->getAllowedGrantTypes(), $grantTypes));
        $client->setRedirectUris($redirectUrls);

        $manager->persist($client);

        return $client;
    }

    /**
     * Make the bearer-token directly for a given user (by email)
     */
    protected function newOauth2AccessToken(DocumentManager $manager, Client $client, User $user, string $token)
    {
        $accessToken = new AccessToken();
        $accessToken->setClient($client);
        $accessToken->setUser($user);
        $accessToken->setToken($token);
        $accessToken->setExpiresAt(PHP_INT_MAX);
        $accessToken->setScope(Oauth2Scopes::USER_STARLING_SUMMARY);

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
