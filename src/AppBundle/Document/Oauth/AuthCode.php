<?php
namespace AppBundle\Document\Oauth;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use FOS\OAuthServerBundle\Document\AuthCode as BaseAuthCode;
use FOS\OAuthServerBundle\Model\ClientInterface;

/**
 * @MongoDB\Document(collection="oauthAuthCode")
 */
class AuthCode extends BaseAuthCode
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
     * @MongoDB\ReferenceOne(targetDocument="User")
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
    <!-- src/Acme/ApiBundle/Resources/config/doctrine/AuthCode.mongodb.xml -->
    <document name="Acme\ApiBundle\Document\AuthCode" db="acme" collection="oauthAuthCode" customId="true">
        <field fieldName="id" id="true" strategy="AUTO" />
        <reference-one target-document="Acme\ApiBundle\Document\Client" field="client" />
    </document>
*/
