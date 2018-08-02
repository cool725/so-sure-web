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
