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
    protected $googleAppDownload;

    /** @var string */
    protected $appleAppDownload;

    /**
     * @param LoggerInterface $logger
     * @param                 $router
     * @param                 $redis
     * @param                 $environment
     * @param                 $branchKey
     * @param                 $googleAppDownload
     * @param                 $appleAppDownload
     */
    public function __construct(
        LoggerInterface $logger,
        $router,
        $redis,
        $environment,
        $branchKey,
        $googleAppDownload,
        $appleAppDownload
    ) {
        $this->logger = $logger;
        $this->router = $router;
        $this->redis = $redis;
        $this->environment = $environment;
        $this->branchKey = $branchKey;
        $this->googleAppDownload = $googleAppDownload;
        $this->appleAppDownload = $appleAppDownload;
    }

    public function downloadAppleLink($source)
    {
        if ($source && $this->environment == 'prod') {
            return sprintf("%s&cs=%s", $this->appleAppDownload, $source);
        } else {
            return $this->appleAppDownload;
        }
    }

    public function downloadGoogleLink($source)
    {
        if ($source && $this->environment == 'prod') {
            return sprintf("%s&utm_source=%s", $this->googleAppDownload, $source);
        } else {
            return $this->googleAppDownload;
        }
    }

    public function googleLink($data, $marketing, $source)
    {
        $data = array_merge($data, [
            '$desktop_url' => $this->downloadGoogleLink($source),
            '$android_url' => $this->downloadGoogleLink($source),
        ]);

        return $this->send($data, $this->marketing($marketing));
    }

    public function appleLink($data, $marketing, $source)
    {
        $data = array_merge($data, [
            '$desktop_url' => $this->downloadAppleLink($source),
            '$ios_url' => $this->downloadAppleLink($source),
        ]);

        return $this->send($data, $this->marketing($marketing));
    }

    public function link($data, $marketing, $source)
    {
        $data = array_merge($data, [
            //'$desktop_url' => $this->router->generate('', true),
            '$ios_url' => $this->appleLink($source),
            '$android_url' => $this->googleLink($source),
        ]);

        return $this->send($data, $this->marketing($marketing));
    }

    protected function marketing($marketing)
    {
        return array_merge($marketing, [
           "sdk" => "api",
        ]);
    }

    protected function send($data, $marketing)
    {
        try {
            $body = array_merge($marketing, [
                'branch_key' => $this->branchKey,
                'data' => json_encode($data)
            ]);
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
