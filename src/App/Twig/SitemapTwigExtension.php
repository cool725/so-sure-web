<?php
namespace App\Twig;

use App\Oauth2Scopes;
use Psr\Log\LoggerInterface;

class SitemapTwigExtension extends \Twig_Extension
{
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('translateSitemapUrl', [$this, 'translateSitemapUrl']),
        );
    }

    public function translateSitemapUrl(string $url): string
    {
        if (!$url || !is_string($url)) {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        static $urlToDescription = [
            null => 'SO-SURE',    // special case for http://wearesosure.com (no /)
            '/'  => 'SO-SURE Homepage',

            '/about/social-insurance'         => 'About Social Insurance',
            '/about/social-insurance/careers' => 'Careers at SO-SURE',
            '/about/social-insurance/privacy' => 'Website privacy policy (GDPR version)',
            '/about/social-insurance/terms'   => 'Terms & Conditions',
            '/phone-insurance'                => 'Get a quote for your phone',
            '/download-app'                   => 'Get covered in seconds using our app (IOS and Android)',
            '/phone-insurance/broken-phone'   => 'Broken phone',
            '/phone-insurance/cracked-screen' => 'Cracked screen',
            '/phone-insurance/loss'           => 'Loss',
            '/phone-insurance/theft'          => 'Theft',
            '/phone-insurance/water-damage'   => 'Water damage',
            '/text-me-the-app'                => 'Get a download link sent to your phone!',

            '/mobile-phone-insurance-for-your-company' => 'Mobile phone insurance for your company',
        ];

        return $urlToDescription[$path] ?? $url;
    }
}
