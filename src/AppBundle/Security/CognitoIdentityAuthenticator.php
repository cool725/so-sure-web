<?php

namespace AppBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authentication\SimplePreAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\HttpUtils;
use Psr\Log\LoggerInterface;

class CognitoIdentityAuthenticator implements SimplePreAuthenticatorInterface, AuthenticationFailureHandlerInterface
{
    const ANON_USER_UNAUTH_PATH = 'anon:unauth';
    const ANON_USER_AUTH_PATH = 'anon:auth';
    const AUTH_PATH = '/api/v1/auth';

    protected $httpUtils;
    protected $logger;

    public function __construct(HttpUtils $httpUtils, LoggerInterface $logger)
    {
        $this->httpUtils = $httpUtils;
        $this->logger = $logger;
    }

    /**
     * @param string $requestContent
     *
     * @return string|null
     */
    public function getCognitoIdentityId($requestContent)
    {
        try {
            $identity = $this->parseIdentity($requestContent);
            if (!$identity || !isset($identity['cognitoIdentityId'])) {
                return null;
            }

            return $identity['cognitoIdentityId'];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param string $requestContent
     *
     * @return string|null
     */
    public function getCognitoIdentityIp($requestContent)
    {
        try {
            $identity = $this->parseIdentity($requestContent);
            if (!$identity || !isset($identity['sourceIp'])) {
                return null;
            }

            return $identity['sourceIp'];
        } catch (\Exception $e) {
            return null;
        }
    }
    
    protected function parseIdentity($requestContent)
    {
        try {
            $this->logger->error($requestContent);
            $data = json_decode($requestContent, true);
            $this->logger->error(print_r($data, true));

            $str = $data['identity'];
            $str = str_replace(',', '&', $str);
            $str = str_replace('{', '', $str);
            $str = str_replace('}', '', $str);
            $str = str_replace(' ', '', $str);
            parse_str($str, $identity);
            
            return $identity;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function createToken(Request $request, $providerKey)
    {
        $user = self::ANON_USER_UNAUTH_PATH;
        $cognitoIdentityId = null;
        // AWS Gateway won't pass identity via get
        if ($request->getMethod() != "GET") {
            $cognitoIdentityId = $this->getCognitoIdentityId($request->getContent());

            if (stripos($request->getPathInfo(), self::AUTH_PATH) !== false) {
                $user = self::ANON_USER_AUTH_PATH;
            }
            
            // Odd issue where aws api gateway isn't passing on the cognito identity from the app
            // so ignore it for now
            // TODO: FIX THIS!!
            if (!$cognitoIdentityId && $user == self::ANON_USER_AUTH_PATH) {
                throw new BadCredentialsException('No Cognito Identifier found');
            }
        }

        return new PreAuthenticatedToken(
            $user,
            $cognitoIdentityId,
            $providerKey
        );
    }

    public function authenticateToken(TokenInterface $token, UserProviderInterface $userProvider, $providerKey)
    {
        if (!$userProvider instanceof CognitoIdentityUserProvider) {
            throw new \InvalidArgumentException(
                sprintf(
                    'The user provider must be an instance of CognitoIdentityUserProvider (%s was given).',
                    get_class($userProvider)
                )
            );
        }

        $cognitoIdentityId = $token->getCredentials();
        $user = $userProvider->loadUserByCognitoIdentityId($cognitoIdentityId);
        $roles = $user ? $user->getRoles() : [];

        if (!$user) {
            if ($token->getUser() == self::ANON_USER_AUTH_PATH) {
                // CAUTION: this message will be returned to the client
                // (so don't put any un-trusted messages / error strings here)
                throw new CustomUserMessageAuthenticationException(
                    sprintf('API Key "%s" does not exist.', $cognitoIdentityId)
                );
            } else {
                $user = $token->getUser();
            }
        }

        return new PreAuthenticatedToken(
            $user,
            $cognitoIdentityId,
            $providerKey,
            $roles
        );
    }

    public function supportsToken(TokenInterface $token, $providerKey)
    {
        return $token instanceof PreAuthenticatedToken && $token->getProviderKey() === $providerKey;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        \AppBundle\Classes\NoOp::noOp([$request, $exception]);

        // Temporarily log to see whats occuring?
        $this->logger->error($exception->getMessage());

        return new Response(
            "Auth failure",
            403
        );
    }
}
