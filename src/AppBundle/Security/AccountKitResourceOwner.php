<?php
namespace AppBundle\Security;

use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use HWI\Bundle\OAuthBundle\OAuth\ResourceOwner\FacebookResourceOwner;

class AccountKitResourceOwner extends FacebookResourceOwner
{
    /**
     * {@inheritdoc}
     */
    public function getAccessToken(Request $request, $redirectUri, array $extraParameters = array())
    {
        $parameters = array_merge(array(
            'code' => $request->query->get('code'),
            'grant_type' => 'authorization_code',
            'access_token' => sprintf("AA|%s|%s", $this->options['client_id'], $this->options['client_secret'])   
        ), $extraParameters);

        $response = $this->doGetTokenRequest($this->options['access_token_url'], $parameters);
        $response = $this->getResponseContent($response);

        $this->validateResponseContent($response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetTokenRequest($url, array $parameters = array())
    {
        return $this->httpRequest($url . '?'. http_build_query($parameters, '', '&'));
    }
}
