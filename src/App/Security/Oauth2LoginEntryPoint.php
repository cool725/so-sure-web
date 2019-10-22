<?php
declare(strict_types=1);
namespace App\Security;

use App\Oauth2Scopes;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Called when a user is not logged in, but reaches code that requires authentication
 *
 * @see https://symfony.com/doc/3.4/components/security/firewall.html#entry-points
 */
class Oauth2LoginEntryPoint implements AuthenticationEntryPointInterface
{
    /** @var UrlGeneratorInterface */
    private $router;
    /**
     * @var LoggerInterface
     */
    private $log;

    public function __construct(UrlGeneratorInterface $router, LoggerInterface $logger)
    {
        $this->router = $router;
        $this->log = $logger;
    }

    /**
     * If we are not logged in, redirect to /login with a flash-message
     *
     * This method receives the current Request object and the exception by which the exception
     * listener was triggered.
     *
     * Unwrap the exception and use that message, or fall back to a default
     *
     * The method should return a Response object
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        $defaultLoginRoute  = 'fos_user_security_login';
        $parameters = [];

        $this->log->notice('in '.__METHOD__, ['request' => $request, 'AuthenticationException' => $authException]);

        if ($authException instanceof InsufficientAuthenticationException) {
            if ($redirection = $this->oauthRequiresLogin($request)) {
                return $redirection;
            };
        }

        return new RedirectResponse($this->router->generate($defaultLoginRoute, $parameters));
    }

    private function oauthRequiresLogin(Request $request) # : ?RedirectResponse
    {
        static $loginRoutesForOauthScopes = [
            Oauth2Scopes::USER_STARLING_SUMMARY => 'starling_bank',
            Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY => 'starling_business',
        ];
        static $extraParameters = [
            Oauth2Scopes::USER_STARLING_SUMMARY => [
                'utm_source' => 'starling',
                'utm_medium' => 'app',
                'utm_campaign' => 'partner'
            ],
            Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY => [
                'utm_source' => 'starling',
                'utm_medium' => 'app',
                'utm_campaign' => 'partner'
            ],
        ];

        $parameters = $request->query->all();

        $scope = $parameters['scope'] ?? null;
        $route = $loginRoutesForOauthScopes[$scope] ?? null;
        if (!$route) {
            return null;
        }

        if (isset($extraParameters[$scope])) {
            $parameters = array_merge($extraParameters[$scope], $parameters);
        }

        return new RedirectResponse($this->router->generate($route, $parameters));
    }
}
