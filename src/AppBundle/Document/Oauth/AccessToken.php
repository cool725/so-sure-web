<?php
namespace AppBundle\Document\Oauth;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use FOS\OAuthServerBundle\Document\AccessToken as BaseAccessToken;
use FOS\OAuthServerBundle\Model\ClientInterface;

/**
 * @MongoDB\Document(collection="oauthAccessToken")
 */
class AccessToken extends BaseAccessToken
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Client")
     */
    protected $client;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     */
    protected $user;

    public function getClient()
    {
        return $this->client;
    }

    public function setClient(ClientInterface $client)
    {
        $this->client = $client;
    }
}

/* @todo
    <!-- src/Acme/ApiBundle/Resources/config/doctrine/AccessToken.mongodb.xml -->
    <document name="Acme\ApiBundle\Document\AccessToken" db="acme" collection="oauthAccessToken" customId="true">
        <field fieldName="id" id="true" strategy="AUTO" />
        <reference-one target-document="Acme\ApiBundle\Document\Client" field="client" />
    </document>
*/
