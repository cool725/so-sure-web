<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

class BranchService
{
    const BASE_URL = 'https://api.branch.io/v1/url';
    const BRANCH_REDIS_KEY = 'branch:shortlinks';
    const BRANCH_REDIS_CACHE = 86400;

    /** @var LoggerInterface */
    protected $logger;

    protected $router;
    protected $redis;

    /** @var string */
    protected $environment;

    /** @var string */
    protected $branchKey;

    /** @var string */
    protected $branchDomain;

    /** @var string */
    protected $googleAppDownload;

    /** @var string */
    protected $appleAppDownload;

    /**
     * @param LoggerInterface   $logger
     * @param                   $router
     * @param                   $redis
     * @param                   $environment
     * @param                   $branchKey
     * @param                   $branchDomain
     * @param                   $googleAppDownload
     * @param                   $appleAppDownload
     */
    public function __construct(
        LoggerInterface $logger,
        $router,
        $redis,
        $environment,
        $branchKey,
        $branchDomain,
        $googleAppDownload,
        $appleAppDownload
    ) {
        $this->logger = $logger;
        $this->router = $router;
        $this->redis = $redis;
        $this->environment = $environment;
        $this->branchKey = $branchKey;
        $this->branchDomain = $branchDomain;
        $this->googleAppDownload = $googleAppDownload;
        $this->appleAppDownload = $appleAppDownload;
    }

    public function getAppleParams($source, $medium, $campaign)
    {
        if ($source || $medium || $campaign) {
            $appleCampaign = sprintf('%s-%s-%s', $source, $campaign, $medium);

            return http_build_query(['cs' => $appleCampaign]);
        }

        return null;
    }

    public function getMarketingParams($source, $medium, $campaign)
    {
        return [
            'channel' => $source,
            'feature' => $medium,
            'campaign' => $campaign,
        ];        
    }

    public function getGoogleParams($source, $medium, $campaign)
    {
        $utm = [];
        if ($source) {
           $utm['utm_source'] = $source;
        }
        if ($medium) {
           $utm['utm_medium'] = $medium;
        }
        if ($campaign) {
           $utm['utm_campaign'] = $campaign;
        }

        return http_build_query($utm);
    }

    /*
    public function autoLinkStandard($source, $medium, $campaign, $custom, $control)
    {
        return $this->autoLink(
            [
                'channel' => $source,
                'feature' => $medium,
                'campaign' => $campaign,
            ],
            array_merge($custom, [
                '$ios_url' => sprintf('%s&%s', urlencode($this->appleAppDownload), $this->getAppleParams($source, $medium, $campaign)),
                '$android_url' => sprintf('%s&%s', urlencode($this->googleAppDownload), $this->getGoogleParams($source, $medium, $campaign)),
            ]),
            $control
        );
    }
    */

    /**
     * @param array $analytics channel, feature, campaign, stage, tags, alias
     * @param array $custom
     * @param array $control $fallback_url, $desktop_url, $ios_url, $ipad_url, $deeplink_path
     *
     * @see https://github.com/BranchMetrics/branch-deep-linking-public-api#creating-a-deep-linking-url
     * @see https://dev.branch.io/getting-started/configuring-links/guide/#analytics-labels
     * @see https://dev.branch.io/getting-started/configuring-links/guide/#redirect-customization
    public function autoLink($analytics, $custom, $control)
    {
        return sprintf('%s?%s',
            $this->branchDomain,
            http_build_query(array_merge($analytics, $custom, $control))
        );
    }

    public function downloadAppleAutoLink($source, $medium, $campaign, $custom, $control)
    {
        $custom = array_merge($custom, [
            '$desktop_url' => sprintf('%s&%s', urlencode($this->appleAppDownload), $this->getAppleParams($source, $medium, $campaign)),
        ]);
        if ($this->environment == 'prod') {
            return $this->autoLinkStandard($source, $medium, $campaign, $custom, $control);
        } else {
            return $this->autoLinkStandard($source, $medium, $campaign, $custom, $control);
        }
    }
     */

    public function downloadAppleLink($source, $medium, $campaign)
    {
        if ($this->environment == 'prod') {
            return sprintf("%s&%s", $this->appleAppDownload, $this->getAppleParams($source, $medium, $campaign));
        } else {
            return sprintf("%s", $this->appleAppDownload);
        }
    }

    public function downloadGoogleLink($source, $medium, $campaign)
    {
        if ($this->environment == 'prod') {
            return sprintf("%s&%s", $this->googleAppDownload, $this->getGoogleParams($source, $medium, $campaign));
        } else {
            return sprintf("%s", $this->googleAppDownload);
        }
    }

    public function googleLink($data, $source, $medium, $campaign)
    {
        $data = array_merge($data, [
            '$desktop_url' => $this->downloadGoogleLink($source, $medium, $campaign),
            '$android_url' => $this->downloadGoogleLink($source, $medium, $campaign),
            '$ios_url' => $this->downloadGoogleLink($source, $medium, $campaign),
        ]);

        return $this->send($data, $source, $medium, $campaign);
    }

    public function appleLink($data, $source, $medium, $campaign)
    {
        $data = array_merge($data, [
            '$desktop_url' => $this->downloadAppleLink($source, $medium, $campaign),
            '$ios_url' => $this->downloadAppleLink($source, $medium, $campaign),
            '$android_url' => $this->downloadAppleLink($source, $medium, $campaign),
        ]);

        return $this->send($data, $source, $medium, $campaign);
    }

    public function link($data, $source, $medium, $campaign)
    {
        $data = array_merge($data, [
            //'$desktop_url' => $this->router->generate('', true),
            '$ios_url' => $this->downloadAppleLink($source, $medium, $campaign),
            '$android_url' => $this->downloadGoogleLink($source, $medium, $campaign),
        ]);

        return $this->send($data, $source, $medium, $campaign);
    }

    public function generateSCode($code)
    {
        $source = 'app';
        $medium = 'share';
        $campaign = 'scode';

        // don't generate scodes for testing
        if ($this->environment == 'test') {
            return null;
        }

        $data = [
            'scode' => $code,
            '$deeplink_path' => sprintf('invite/scode/%s', $code),
            '$desktop_url' => $this->router->generate('scode', ['code' => $code], true),
            '$ios_url' => $this->downloadAppleLink($source, $medium, $campaign),
            '$android_url' => $this->downloadGoogleLink($source, $medium, $campaign),
        ];

        return $this->send($data, $source, $medium, $campaign, $code);
    }

    protected function send($data, $source, $medium, $campaign, $alias = null)
    {
        try {
            $body = array_merge($this->getMarketingParams($source, $medium, $campaign), [
                'branch_key' => $this->branchKey,
                'data' => json_encode($data),
                "sdk" => "api"
            ]);

            if ($alias) {
                $body = array_merge($body, [
                    'alias' => $alias
                ]);
            }

            $key = sprintf('%s:%s', self::BRANCH_REDIS_KEY, sha1(json_encode($body)));
            if ($url = $this->redis->get($key)) {
                return $url;
            }

            $this->logger->debug(sprintf('Sending %s to branch', json_encode($body)));
            $client = new Client();
            $res = $client->request('POST', self::BASE_URL, [
                'json' => $body,
            ]);
            $body = (string) $res->getBody();
            $this->logger->debug(sprintf('Received %s from branch', $body));

            $response = json_decode($body, true)['url'];
            $this->redis->setex($key, self::BRANCH_REDIS_CACHE, $response);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in branch request'), ['exception' => $e]);
        }
    }
}
