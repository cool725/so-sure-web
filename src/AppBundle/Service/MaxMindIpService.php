<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GeoIp2\Database\Reader;
use AppBundle\Document\Coordinates;
use AppBundle\Document\IdentityLog;
use GeoJson\Geometry\Point;

class MaxMindIpService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $reader;

    protected $ip;
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
        $this->ip = $ip;

        // reset data in case city throws exception
        $this->data = null;

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

    public function getData()
    {
        return $this->data;
    }

    public function getCountry()
    {
        if (!$this->data) {
            return null;
        }

        return $this->data->country->isoCode;
    }

    public function getCoordinates()
    {
        if (!$this->data) {
            return null;
        }

        $coordinates = new Coordinates();
        $coordinates->coordinates = [$this->data->location->longitude, $this->data->location->latitude];

        return $coordinates;
    }

    public function getIdentityLog($ip, $cognitoId)
    {
        $this->find($ip);
        $identityLog = new IdentityLog();
        $identityLog->setIp($this->ip);
        $identityLog->setCountry($this->getCountry());
        $identityLog->setLoc($this->getCoordinates());
        $identityLog->setCognitoId($cognitoId);

        return $identityLog;
    }
}
