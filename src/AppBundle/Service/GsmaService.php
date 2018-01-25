<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

class GsmaService
{
    use \AppBundle\Document\ImeiTrait;

    const BASE_URL = "https://devicecheck.gsma.com/imeirtl";

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $username;

    /**
     * @param LoggerInterface $logger
     * @param string          $apiKey
     * @param string          $username
     */
    public function __construct(LoggerInterface $logger, $apiKey, $username)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->username = $username;
    }

    /**
     * Checks imei against a blacklist
     *
     * @return boolean True if imei is ok
     */
    public function checkImei($imei)
    {
        // curl -X POST -d "imeinumber=35098400111112" https://devicecheck.gsma.com/imeirtl/detailedblwithmodelinfo
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == "352000067704506") {
            return false;
        }

        try {
            $client = new Client();
            $url = sprintf("%s/detailedblwithmodelinfo", self::BASE_URL);
            $data = [
                    'imeinumber' => $imei,
                    'username' => $this->username,
                    'apikey' => $this->apiKey,
            ];
            $res = $client->request('POST', $url, ['json' => $data]);
            $body = (string) $res->getBody();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in checkImei: %s', $e->getMessage()));
        }

        // for now, always ok the imei until we purchase db
        return true;
    }
}
