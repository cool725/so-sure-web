<?php
namespace AppBundle\Service;

use HWI\Bundle\OAuthBundle\OAuth\ResourceOwner\AccountkitResourceOwner;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class AccountkitTwigExtension extends \Twig_Extension
{
    /** @var AccountkitResourceOwner */
    protected $oauth;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param AccountkitResourceOwner $oauth
     * @param LoggerInterface         $logger
     */
    public function __construct(
        AccountkitResourceOwner $oauth,
        LoggerInterface $logger
    ) {
        $this->oauth = $oauth;
        $this->logger = $logger;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('accountkit_login', array($this, 'login')),
            new \Twig_SimpleFunction('accountkit_state', array($this, 'state')),
        );
    }

    public function login($redirectUrl)
    {
        return $this->oauth->getAuthorizationUrl($redirectUrl, []);
    }

    public function state()
    {
        // Bit of a hack to get the csrf state var for use in the form
        // Getting var directly would require quite more changes to the hwi oauth codebase
        // So much simpler to just generate the auth url and pull the state out of that
        $url = $this->oauth->getAuthorizationUrl('http://localhost', []);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $queryItems);

        return $queryItems['state'];
    }

    public function getName()
    {
        return 'app_twig_accountkit';
    }
}
