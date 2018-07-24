<?php
namespace AppBundle\Document\Oauth;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use FOS\OAuthServerBundle\Document\Client as BaseClient;

/**
 * @MongoDB\Document(collection="oauthClient", repositoryClass="AppBundle\Repository\Oauth\OauthClientRepository")
 */
class Client extends BaseClient
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;
}

/* @todo
    <!-- src/Acme/ApiBundle/Resources/config/doctrine/Client.mongodb.xml -->
    <document name="Acme\ApiBundle\Document\Client" db="acme" collection="oauthClient" customId="true">
    <field fieldName="id" id="true" strategy="AUTO" />
    </document>
 */
