<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

class BranchService
{
    const BASE_URL = 'https://api.branch.io/v1/url';

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $branchKey;

    /** @var string */
    protected $googleAppDownload;

    /** @var string */
    protected $appleAppDownload;

    /**
     * @param LoggerInterface $logger
     * @param                 $router
     * @param                 $branchKey
     */
    public function __construct(
        LoggerInterface $logger,
        $router,
        $branchKey,
        $googleAppDownload,
        $appleAppDownload
    ) {
        $this->logger = $logger;
        $this->router = $router;
        $this->branchKey = $branchKey;
        $this->googleAppDownload = $googleAppDownload;
        $this->appleAppDownload = $appleAppDownload;
    }

    public function downloadAppleLink($source)
    {
        if ($source) {
            return sprintf("%s&cs=%s", $this->appleAppDownload, $source);
        } else {
            return $this->appleAppDownload;
        }
    }

    public function downloadGoogleLink($source)
    {
        if ($source) {
            return sprintf("%s&utm_source=%s", $this->googleAppDownload, $source);
        } else {
            return $this->googleAppDownload;
        }
    }

    public function googleLink($data, $marketing, $source)
    {
        $marketing = array_merge($marketing, [
           "sdk" => "api", 
        ]);
        $data = array_merge($data, [
            '$desktop_url' => $this->downloadGoogleLink($source),
            '$android_url' => $this->downloadGoogleLink($source),
        ]);
        $response = $this->send($data, $marketing);

        return $response['url'];
    }

    public function appleLink($data, $marketing, $source)
    {
        $marketing = array_merge($marketing, [
           "sdk" => "api", 
        ]);
        $data = array_merge($data, [
            '$desktop_url' => $this->downloadAppleLink($source),
            '$ios_url' => $this->downloadAppleLink($source),
        ]);
        $response = $this->send($data, $marketing);

        return $response['url'];
    }

    public function link($data, $marketing, $source)
    {
        $marketing = array_merge($marketing, [
           "sdk" => "api", 
        ]);
        $data = array_merge($data, [
            //'$desktop_url' => $this->router->generate('', true),
            '$ios_url' => $this->appleLink($source),
            '$android_url' => $this->googleLink($source),
        ]);
        $response = $this->send($data, $marketing);

        return $response['url'];
    }

    protected function send($data, $marketing)
    {
        try {
            $body = array_merge($marketing, [
                'branch_key' => $this->branchKey,                                         
                'data' => json_encode($data)
            ]);
            $this->logger->debug(sprintf('Sending %s to branch', json_encode($body)));
            $client = new Client();
            $res = $client->request('POST', self::BASE_URL, [
                'json' => $body,
            ]);
            $body = (string) $res->getBody();
            $this->logger->debug(sprintf('Received %s from branch', $body));

            return json_decode($body, true);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in branch request'), ['exception' => $e]);
        }
    }
}
