<?php

namespace AppBundle\Security;

use AppBundle\Document\IdentityLog;
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
use AppBundle\Document\User;
use Psr\Log\LoggerInterface;

class CognitoIdentityAuthenticator implements SimplePreAuthenticatorInterface, AuthenticationFailureHandlerInterface
{
    const ANON_USER_UNAUTH_PATH = 'anon:unauth';
    const ANON_USER_AUTH_PATH = 'anon:auth';
    const ANON_USER_PARTIAL_AUTH_PATH = 'anon:partial';
    const AUTH_PATH = '/api/v1/auth';
    const PARTIAL_AUTH_PATH = '/api/v1/partial';

    protected $httpUtils;
    protected $logger;

    public function __construct(HttpUtils $httpUtils, LoggerInterface $logger)
    {
        $this->httpUtils = $httpUtils;
        $this->logger = $logger;
    }

    /**
     * @param resource|string $requestContent
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

    /**
     * @param string $requestContent
     *
     * @return string|null
     */
    public function getCognitoIdentityUserAgent($requestContent)
    {
        try {
            $identity = $this->parseIdentity($requestContent);
            if (!$identity || !isset($identity['userAgent'])) {
                return null;
            }

            return $identity['userAgent'];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param string $requestContent
     *
     * @return string|null
     */
    public function getCognitoIdentitySdk($requestContent)
    {
        $userAgent = $this->getCognitoIdentityUserAgent($requestContent);
        if ($userAgent && mb_stripos($userAgent, 'aws-sdk-android') === false) {
            return IdentityLog::SDK_ANDROID;
        } elseif ($userAgent && mb_stripos($userAgent, 'aws-sdk-iOS') === false) {
            return IdentityLog::SDK_IOS;
        } elseif ($userAgent && mb_stripos($userAgent, 'aws-sdk-javascript') === false) {
            // TODO: Test this when released
            return IdentityLog::SDK_JAVASCRIPT;
        }

        return IdentityLog::SDK_UNKNOWN;
    }

    protected function parseIdentity($requestContent)
    {
        try {
            return json_decode($requestContent, true);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function createToken(Request $request, $providerKey)
    {
        $user = self::ANON_USER_UNAUTH_PATH;
        $cognitoIdentityId = $this->getCognitoIdentityId($request->getContent());

        if (mb_stripos($request->getPathInfo(), self::PARTIAL_AUTH_PATH) !== false) {
            $user = self::ANON_USER_PARTIAL_AUTH_PATH;
        } elseif (mb_stripos($request->getPathInfo(), self::AUTH_PATH) !== false) {
            $user = self::ANON_USER_AUTH_PATH;
        }

        if (($user == self::ANON_USER_AUTH_PATH || $user == self::ANON_USER_PARTIAL_AUTH_PATH) &&
            !$cognitoIdentityId) {
            throw new BadCredentialsException('No Cognito Identifier found');
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
                    sprintf('Cognity Identity "%s" is not authenticated.', $cognitoIdentityId)
                );
            } else {
                $user = $token->getUser();
            }
        }

        if ($user instanceof User && (
            !$user->isEnabled() || $user->isLocked()
        )) {
            throw new CustomUserMessageAuthenticationException(
                sprintf('User %s (%s) is disabled', $user->getName(), $user->getEmail())
            );
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
        \AppBundle\Classes\NoOp::ignore([$request]);
        $this->logger->debug($exception->getMessage());

        return new Response(
            "Auth failure",
            403
        );
    }
}
