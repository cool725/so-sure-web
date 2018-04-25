<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class ShortLinkService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $googleAppName;

    /** @var string */
    protected $googleApiKey;

    /** @var \Domnikl\Statsd\Client */
    protected $statsd;

    /**
     * @param LoggerInterface        $logger
     * @param string                 $googleAppName
     * @param string                 $googleApiKey
     * @param \Domnikl\Statsd\Client $statsd
     */
    public function __construct(
        LoggerInterface $logger,
        $googleAppName,
        $googleApiKey,
        \Domnikl\Statsd\Client $statsd
    ) {
        $this->logger = $logger;
        $this->googleAppName = $googleAppName;
        $this->googleApiKey = $googleApiKey;
        $this->statsd = $statsd;
    }

    /**
     * @param string $url
     *
     * @return string
     */
    public function addShortLink($url)
    {
        try {
            $this->statsd->startTiming("google.shortlink");

            $client = new \Google_Client();
            $client->setApplicationName($this->googleAppName);
            $client->setDeveloperKey($this->googleApiKey);
            $service = new \Google_Service_Urlshortener($client);
            $gUrl = new \Google_Service_Urlshortener_Url();
            $gUrl->longUrl = $url;
            $result = $service->url->insert($gUrl);

            $this->statsd->endTiming("google.shortlink");

            return $result['id'];
        } catch (\Exception $e) {
            return $url;
        }
    }
}
