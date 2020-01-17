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

        // @codingStandardsIgnoreStart
        static $urlToDescription = [
            null => 'so-sure',    // special case for http://wearesosure.com (no /)
            '/'  => 'Homepage',
            '/blog/' => 'Blog',
            '/blog' => 'Blog',
            '/blog/looking-back-and-forward' => 'Looking Back and Forward',
            '/blog/what-to-do-if-you-break-your-phone-screen' => 'What to do if You Break Your Phone Screen',
            '/blog/5-great-gadgets-from-2018-for-the-january-sales' => '5 Great Gadgets from 2018 for the January Sales',
            '/blog/all-i-want-for-christmas-is-a-new-phone' => 'All I Want for Christmas is a New Phone',
            '/blog/broken-promises' => 'Broken Promises',
            '/blog/so-sure-people-dylan-bourguignon' => 'so-sure People Dylan Bourguignon',
            '/blog/5-ways-to-protect-your-valuables-abroad' => '5 Ways to Protect your Valuables Abroad',
            '/blog/englands-most-trusting-cities' => 'England\'s Most Trusting Cities',
            '/blog/starling-bank-and-so-sure-team-up-to-offer-mobile-phone-insurance-through-the-starling-marketplace' => 'Starling Bank and so-sure Team Up to Offer Mobile Phone Insurance Through the Starling App',
            '/blog/dirty-tricks-to-watch-out-for-when-buying-insurance' => 'Dirty Tricks to Watch Out for when Buying Insurance',
            '/blog/googles-pixel-3-takes-on-apples-iphone-xs' => 'Google\'s Pixel 3 Takes on Apple\'s iPhone XS',
            '/blog/the-development-of-insurance-as-we-know-it' => 'The Development of Insurance as we Know it',
            '/blog/introducing-social-insurance' => 'Introducing Social Insurance',
            '/blog/samsungs-note-9-takes-on-apples-iphone-x' => 'Samsung\'s Note 9 Takes on Apple\'s iPhone X',
            '/blog/what-to-look-out-for-when-buying-phone-insurance' => 'What to Look for when Buying Phone Insurance',
            '/blog/the-weird-and-wonderful-origins-of-insurance-from-the-babylonians-to-benjamin-franklin' => 'The Weird and Wonderful Origins of Insurance from the Babylonians to Benjamin Franklin',
            '/blog/how-to-fix-a-problem-like-insurance' => 'How to Fix a Problem like Insurance',
            '/blog/the-insurtech-revolution' => 'The Insurtech Revolution',
            '/blog/the-internet-of-things' => 'The Internet of Things',
            '/blog/samsung-galaxy-s9-versus-the-s9-plus' => 'Samsung Galaxy S9 Versus the S9+',
            '/blog/mwc-2018-preview' => 'MWC 2018 Preview',
            '/blog/money-saving-tips' => 'Money Saving Tips',
            '/blog/mobile-phone-insurance-buying-guide' => 'Mobile Phone Insurance Buying Guide',
            '/blog/disruptive-technology-what-is-it' => 'Disruptive Technology What is it',
            '/blog/our-top-5-winter-sports-insurance-tips' => 'Our Top 5 Winter Sports Insurance Tips',
            '/blog/3-technologies-that-will-shape-the-future-of-insurance' => '3 Technologies that will Shape the Future of Insurance',
            '/blog/phone-insurance-guide' => 'Phone Insurance Guide',
            '/about/social-insurance' => 'About so-sure',
            '/about/social-insurance/careers' => 'Careers',
            '/about/social-insurance/privacy' => 'Privacy Policy',
            '/about/social-insurance/terms' => 'Terms & Conditions',
            '/about/social-insurance/how-to-contact-so-sure' => 'Contact Us',
            '/phone-insurance' => 'Phone Insurance',
            '/download-app' => 'Get covered in seconds using our app (IOS and Android)',
            '/phone-insurance/broken-phone' => 'Broken phone',
            '/phone-insurance/cracked-screen' => 'Cracked screen',
            '/phone-insurance/loss' => 'Loss',
            '/phone-insurance/theft' => 'Theft',
            '/phone-insurance/water-damage' => 'Water damage',
            '/text-me-the-app' => 'Get a download link sent to your phone!',
            '/social-insurance' => 'Social Insurance',
            '/company-phone-insurance' => 'Mobile phone insurance for your company',
            '/phone-insurance/apple' => 'Apple Insurance',
            '/phone-insurance/alcatel' => 'Alcatel Insurance',
            '/phone-insurance/htc' => 'HTC Insurance',
            '/phone-insurance/samsung' => 'Samsung Insurance',
            '/phone-insurance/asus' => 'Asus Insurance',
            '/phone-insurance/elephone' => 'Elephone Insurance',
            '/phone-insurance/google' => 'Google Insurance',
            '/phone-insurance/hp' => 'HP Insurance',
            '/phone-insurance/huawei' => 'Huawei Insurance',
            '/phone-insurance/kodak' => 'Kodak Insurance',
            '/phone-insurance/lg' => 'LG Insurance',
            '/phone-insurance/microsoft' => 'Microsoft Insurance',
            '/phone-insurance/motorola' => 'Motorola Insurance',
            '/phone-insurance/nokia' => 'Nokia Insurance',
            '/phone-insurance/oneplus' => 'OnePlus Insurance',
            '/phone-insurance/oppo' => 'Oppo Insurance',
            '/phone-insurance/razer' => 'Razer Insurance',
            '/phone-insurance/sony' => 'Sony Insurance',
            '/phone-insurance/wileyfox' => 'WileyFox Insurance',
            '/phone-insurance/xiaomi' => 'Xiaomi Insurance',
            '/phone-insurance/blackberry' => 'Blackberry Insurance',
            '/phone-insurance/vodafone' => 'Vodafone Insurance',
            '/phone-insurance/cat' => 'Cat Insurance',
            '/claim' => 'Make a claim',
            '/faq' => 'FAQ'
        ];
        // @codingStandardsIgnoreEnd

        return $urlToDescription[$path] ?? $url;
    }
}
