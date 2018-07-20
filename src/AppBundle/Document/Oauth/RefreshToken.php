<?php
namespace AppBundle\Document\Oauth;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use FOS\OAuthServerBundle\Document\RefreshToken as BaseRefreshToken;
use FOS\OAuthServerBundle\Model\ClientInterface;
use AppBundle\Document\User;

/**
 * @MongoDB\Document(collection="oauthRefreshToken")
 */
class RefreshToken extends BaseRefreshToken
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
    <!-- src/Acme/ApiBundle/Resources/config/doctrine/RefreshToken.mongodb.xml -->
    <document name="Acme\ApiBundle\Document\RefreshToken" db="acme" collection="oauthRefreshToken" customId="true">
        <field fieldName="id" id="true" strategy="AUTO" />
        <reference-one target-document="Acme\ApiBundle\Document\Client" field="client" />
    </document>
*/
