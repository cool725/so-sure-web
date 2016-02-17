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

    /**
     * @param LoggerInterface $logger
     * @param string          $googleAppName
     * @param string          $googleApiKey
     */
    public function __construct(LoggerInterface $logger, $googleAppName, $googleApiKey)
    {
        $this->logger = $logger;
        $this->googleAppName = $googleAppName;
        $this->googleApiKey = $googleApiKey;
    }

    /**
     * @param string $url
     *
     * @return string
     */
    public function addShortLink($url)
    {
        try {
            $client = new \Google_Client();
            $client->setApplicationName($this->googleAppName);
            $client->setDeveloperKey($this->googleApiKey);
            $service = new \Google_Service_Urlshortener($client);
            $gUrl = new \Google_Service_Urlshortener_Url();
            $gUrl->longUrl = $url;
            $result = $service->url->insert($gUrl);

            return $result['id'];
        } catch (\Exception $e) {
            return $url;
        }
    }
}
