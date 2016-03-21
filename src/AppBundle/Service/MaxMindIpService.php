<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GeoIp2\Database\Reader;

class MaxMindIpService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $reader;

    protected $data;

    /**
     * @param LoggerInterface $logger
     * @param string          $db
     */
    public function __construct(LoggerInterface $logger, $db)
    {
        $this->logger = $logger;
        if (file_exists($db)) {
            $this->reader = new Reader($db);
        }
    }

    /**
     * Find a ip address
     *
     * @param string $ip
     */
    public function find($ip)
    {
        if (!$this->reader) {
            return null;
        }
        try {
            $this->data = $this->reader->city($ip);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to query ip %s Err: %s', $ip, $e->getMessage()));
        }

        return $this->data;
    }

    public function getCountry()
    {
        if (!$this->data) {
            return null;
        }

        return $this->data->country->isoCode;
    }

    public function getGeoJson()
    {
        if (!$this->data) {
            return null;
        }

        // geoPhp = geoJson as php array
        $geoPhp = ['type' => 'Point', 'coordinates' => [
            $this->data->location->latitude,
            $this->data->location->longitude
        ]];

        return json_encode($geoPhp);
    }
}
