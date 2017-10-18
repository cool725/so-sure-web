<?php
namespace AppBundle\Security;

use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use HWI\Bundle\OAuthBundle\OAuth\ResourceOwner\GenericOAuth2ResourceOwner;

class StarlingResourceOwner extends GenericOAuth2ResourceOwner
{
}
