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
            '/blog/starling-bank-and-so-sure-team-up-to-offer-mobile-phone-insurance-through-the-starling-marketplace' => 'Starling Bank and SO-SURE up to offer mobile phone insurance',
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
            '/blog/3-technologies-that-will-shape-the-future-of-insurance' => '3 Technologies That Will Shape The Future of Insurance',
            '/blog/phone-insurance-guide' => 'Phone Insurance Guide',
            '/blog/most-durable-phones' => 'Most Durable Phones',
            '/blog/samsung-galaxy-rumours' => 'Samsung Galaxy S11 (S20) Rumours & News',
            '/blog/best-battery-life-phones' => 'Phones With The Best Battery Life',
            '/blog/iphone-problems-and-solutions' => 'Most Common iPhone Problems And Solutions',
            '/blog/best-phone-cases' => 'Best Phone Cases 2020',
            '/blog/best-car-phone-mounts' => 'Best Car Phone Mounts 2020',
            '/blog/most-instagrammed-dog-breeds' => 'Most Instagrammed Dog Breeds',
            '/blog/why-does-my-phone-keep-crashing' => 'Why Does My Phone Keep Crashing & Restarting',
            '/blog/best-screen-protectors' => 'Best Screen Protectors',
            '/blog/applecare-vs-phone-insurance' => 'AppleCare+ vs iPhone Insurance',
            '/blog/best-phone-security-apps' => 'Best Phone Security Apps',
            '/blog/new-iphone-se2-rumour-hub' => 'New iPhone SE2 Rumour Hub',
            '/blog/why-is-my-phone-so-hot' => 'Why Is My Phone So Hot?',
            '/blog/common-mobile-phone-faults-and-solutions' => 'Common Mobile Phone Faults and Solutions',
            '/blog/samsung-galaxy-tips-and-tricks' => 'Samsung Galaxy Tips & Tricks',
            '/blog/how-to-fix-cracked-phone-screen' => 'What To Do About A Cracked Phone Screen',
            '/blog/phone-insurance-vs-contents-insurance' => 'Phone Insurance vs Contents Insurance',
            '/blog/new-huawei-release-rumour-hub' => 'New Huawei Release Rumour Hub',
            '/blog/best-phone-running-armbands' => 'Best Phone Running Armbands',
            '/blog/best-waterproof-phone-cases' => 'Best Waterproof Phone Cases',
            '/blog/uk-phone-statistics' => 'UK Phone Statistics',
            '/blog/sony-xperia-1-II-release-rumours' => 'New Sony Xperia 1 II Release Rumour Hub',
            '/blog/how-long-do-mobile-phones-last' => 'How Long Do Mobile Phones Last?',
            '/blog/halloween-horror-stories' => 'Halloween Horror Stories',
            '/blog/excessive-phone-use-can-lead-to-stress-and-anxiety' => 'Excessive Phone Use Can Lead To Stress And Anxiety.',
            '/blog/what-is-contents-insurance' => 'What is Contents Insurance',
            '/blog/stay-at-home' => 'It Pays To Stay At Home',
            '/blog/what-is-phone-cosmetic-damage' => 'What Is Phone Cosmetic Damage?',
            '/blog/contents-insurance-in-a-shared-house' => 'Contents Insurance In A Shared House',
            '/blog/what-is-a-single-article-limit' => 'What Is A Single Article Limit?',
            '/blog/is-contents-insurance-mandatory' => 'Is Contents Insurance Mandatory?',
            '/blog/a-guide-to-student-contents-insurance' => 'A Guide To Student Contents Insurance',
            '/blog/what-counts-as-high-value-contents' => 'What Counts as High-Value Contents?',
            '/blog/how-to-transfer-data-to-your-new-phone' => 'How To Transfer Data To Your New Phone',
            '/blog/samsung-care-plus-vs-phone-insurance' => 'Samsung Care Plus vs Phone Insurance',
            '/blog/dog-damage-and-other-findings-from-our-new-research' => 'Dog Damage and Other Findings From Our New Research',
            '/blog/cost-of-living-in-fictional-homes' => 'How much would it cost to live in these famous fictional homes?',
            '/blog/what-does-phone-insurance-cover' => 'What Does Mobile Phone Insurance Cover?',
            '/blog/how-to-make-a-successful-mobile-insurance-claim' => 'How to Make a Successful Mobile Insurance Claim',
            '/about/social-insurance' => 'About so-sure',
            '/about/social-insurance/careers' => 'Careers',
            '/about/social-insurance/privacy' => 'Privacy Policy',
            '/about/social-insurance/terms' => 'Terms & Conditions',
            '/about/social-insurance/how-to-contact-so-sure' => 'Contact Us',
            '/phone-insurance' => 'Phone Insurance',
            '/contents-insurance' => 'Contents Insurance',
            '/download-app' => 'Get covered in seconds using our app (IOS and Android)',
            '/phone-insurance/broken-phone' => 'Broken phone',
            '/phone-insurance/cracked-screen' => 'Cracked screen',
            '/phone-insurance/loss' => 'Loss',
            '/phone-insurance/theft' => 'Theft',
            '/phone-insurance/water-damage' => 'Water damage',
            '/text-me-the-app' => 'Get a download link sent to your phone!',
            '/social-insurance' => 'Social Insurance',
            '/company-phone-insurance' => 'Business Mobile Phone Insurance',
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
            '/phone-insurance/vodafone' => 'Vodafone Insurance',
            '/phone-insurance/cat' => 'Cat Insurance',
            '/phone-insurance/second-hand' => 'Second Hand',
            '/phone-insurance/refurbished' => 'Refurbished',
            '/claim' => 'Make a claim',
            '/claim/phone-insurance' => 'Make a Phone Insurance claim',
            '/claim/contents-insurance' => 'Make a Contents Insurance claim',
            '/faq' => 'FAQ'
        ];
        // @codingStandardsIgnoreEnd

        return $urlToDescription[$path] ?? $url;
    }
}
