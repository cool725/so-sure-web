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

    /** @var RouterService */
    protected $routerService;

    /** @var \Predis\Client */
    protected $redis;

    /** @var string */
    protected $environment;

    /** @var string */
    protected $branchKey;

    /** @var string */
    protected $branchSecret;

    /** @var string */
    protected $branchDomain;

    /** @var string */
    protected $googleAppDownload;

    /** @var string */
    protected $appleAppDownload;

    /**
     * @param LoggerInterface $logger
     * @param RouterService   $routerService
     * @param \Predis\Client  $redis
     * @param string          $environment
     * @param string          $branchKey
     * @param string          $branchSecret
     * @param string          $branchDomain
     * @param string          $googleAppDownload
     * @param string          $appleAppDownload
     */
    public function __construct(
        LoggerInterface $logger,
        RouterService $routerService,
        \Predis\Client $redis,
        $environment,
        $branchKey,
        $branchSecret,
        $branchDomain,
        $googleAppDownload,
        $appleAppDownload
    ) {
        $this->logger = $logger;
        $this->routerService = $routerService;
        $this->redis = $redis;
        $this->environment = $environment;
        $this->branchKey = $branchKey;
        $this->branchSecret = $branchSecret;
        $this->branchDomain = $branchDomain;
        $this->googleAppDownload = $googleAppDownload;
        $this->appleAppDownload = $appleAppDownload;
    }

    public function getAppleParams($source, $medium, $campaign)
    {
        if ($source || $medium || $campaign) {
            $appleCampaign = urlencode(sprintf('%s-%s-%s', $source, $campaign, $medium));
            // 40 char limit https://itunespartner.apple.com/en/apps/faq/App%20Analytics_Campaigns
            if (mb_strlen($appleCampaign) > 40) {
                $appleCampaign = urlencode(sprintf('%s-%s', $source, $campaign));
            }
            if (mb_strlen($appleCampaign) > 40) {
                $appleCampaign = mb_substr(urlencode(sprintf('%s', $campaign)), 0, 40);
            }

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
                '$ios_url' => sprintf(
                    '%s&%s',
                    urlencode($this->appleAppDownload),
                    $this->getAppleParams($source, $medium, $campaign)
                ),
                '$android_url' => sprintf(
                    '%s&%s',
                    urlencode($this->googleAppDownload),
                    $this->getGoogleParams($source, $medium, $campaign)
                ),
            ]),
            $control
        );
    }

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
            '$desktop_url' => sprintf(
                '%s&%s',
                urlencode($this->appleAppDownload),
                $this->getAppleParams($source, $medium, $campaign)
            ),
        ]);
        if ($this->environment == 'prod') {
            return $this->autoLinkStandard($source, $medium, $campaign, $custom, $control);
        } else {
            return $this->autoLinkStandard($source, $medium, $campaign, $custom, $control);
        }
    }
    */

    /**
     * @param string $source
     * @param string $medium
     * @param string $campaign
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
            //'$desktop_url' => $this->routerService->generate('', true),
            '$ios_url' => $this->downloadAppleLink($source, $medium, $campaign),
            '$android_url' => $this->downloadGoogleLink($source, $medium, $campaign),
        ]);

        return $this->send($data, $source, $medium, $campaign);
    }

    public function linkToAppleDownload($medium)
    {
        return $this->routerService->generate('download_apple', ['medium' => $medium]);
    }

    public function linkToGoogleDownload($medium)
    {
        return $this->routerService->generate('download_google', ['medium' => $medium]);
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
            '$desktop_url' => $this->routerService->generateUrl('scode', ['code' => $code]),
            '$ios_url' => $this->downloadAppleLink($source, $medium, $campaign),
            '$android_url' => $this->downloadGoogleLink($source, $medium, $campaign),
        ];

        return $this->send($data, $source, $medium, $campaign, $this->getDiacriticsSafeText($code));
    }

    public function getDiacriticsSafeText($text)
    {
        setlocale(LC_CTYPE, 'en_GB.utf8');
        return iconv("UTF-8", 'US-ASCII//TRANSLIT', $text);
    }

    /**
     * Get the data from branch, merge with the updated data provided, then update branch
     */
    public function update($url, $data)
    {
        try {
            $url = sprintf('%s?url=%s&branch_key=%s', self::BASE_URL, $url, $this->branchKey);
            $client = new Client();
            $res = $client->request('GET', $url);
            $body = (string) $res->getBody();
            $this->logger->debug(sprintf('Received %s from branch', $body));

            $data = array_merge(json_decode($body, true), $data);

            $body = [
                'branch_key' => $this->branchKey,
                'branch_secret' => $this->branchSecret,
                'data' => json_encode($data),
                "sdk" => "api"
            ];

            $this->logger->debug(sprintf('Sending %s to branch', json_encode($body)));
            $res = $client->request('PUT', $url, [
                'json' => $body,
            ]);
            $body = (string) $res->getBody();
            $this->logger->debug(sprintf('Received %s from branch', $body));

            $response = json_decode($body, true);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in branch request'), ['exception' => $e]);
        }
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
