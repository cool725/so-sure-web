<?php
namespace App\Twig;

use App\Oauth2Scopes;
use Psr\Log\LoggerInterface;

class Oauth2TwigExtension extends \Twig_Extension
{
    /** @var LoggerInterface */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter(
                'oauth2ScopeDescription',
                [$this, 'oauth2ScopeDescription'],
                array('is_safe' => array('html'))
            ),
        );
    }

    public function oauth2ScopeDescription($scopeName): string
    {
        if (!$scopeName || !is_string($scopeName)) {
            return '';
        }
        $description = Oauth2Scopes::scopeToDescription($scopeName);
        if (empty($description)) {
            $this->logger->notice('scopeToDescription is not defined', ['scope' => $scopeName]);
            return '';
        }

        return $description['intro'] . '<ul><li>' . implode('</li><li>', $description['points']) . '</li></ul>';
    }
}
