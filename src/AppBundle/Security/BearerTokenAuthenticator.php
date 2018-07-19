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
use AppBundle\Document\User;
use Psr\Log\LoggerInterface;

class BearerTokenAuthenticator implements SimplePreAuthenticatorInterface, AuthenticationFailureHandlerInterface
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


    protected function parseIdentity($requestContent)
    {
        $this->logger->critical(__METHOD__);
        try {
            return json_decode($requestContent, true);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function createToken(Request $request, $providerKey)
    {
        $this->logger->critical(__METHOD__);
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
        $this->logger->critical(__METHOD__);
        if (!$userProvider instanceof BearerTokenUserProvider) {
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
        $this->logger->critical(__METHOD__);
        return $token instanceof PreAuthenticatedToken && $token->getProviderKey() === $providerKey;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $this->logger->critical(__METHOD__);
        \AppBundle\Classes\NoOp::ignore([$request]);
        $this->logger->debug($exception->getMessage());

        return new Response(
            "Auth failure",
            403
        );
    }
}
