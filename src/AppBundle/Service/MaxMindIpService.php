<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GeoIp2\Database\Reader;
use AppBundle\Document\Coordinates;
use AppBundle\Document\IdentityLog;
use GeoJson\Geometry\Point;

class MaxMindIpService
{
    const QUERY_CITY = 'city';
    const QUERY_COUNTRY = 'country';

    /** @var LoggerInterface */
    protected $logger;

    /** @var \Domnikl\Statsd\Client */
    protected $statsd;

    protected $cityReader;
    protected $countryReader;

    protected $ip;
    protected $data;

    /**
     * @param LoggerInterface $logger
     * @param string          $cityDb
     * @param string          $countryDb
     */
    public function __construct(LoggerInterface $logger, $cityDb, $countryDb, \Domnikl\Statsd\Client $statsd)
    {
        $this->logger = $logger;
        if (file_exists($cityDb)) {
            $this->cityReader = new Reader($cityDb);
        }
        if (file_exists($countryDb)) {
            $this->countryReader = new Reader($countryDb);
        }

        $this->statsd = $statsd;
    }

    /**
     * Find a ip address
     *
     * @param string $ip
     */
    public function find($ip, $queryType = null)
    {
        if (!$queryType) {
            $queryType = self::QUERY_CITY;
        }
        $this->ip = $ip;

        // reset data in case city throws exception
        $this->data = null;

        try {
            if ($queryType == self::QUERY_CITY) {
                if (!$this->cityReader) {
                    return null;
                }
                $this->data = $this->cityReader->city($ip);
            } elseif ($queryType == self::QUERY_COUNTRY) {
                if (!$this->countryReader) {
                    return null;
                }
                $this->data = $this->countryReader->country($ip);
            } else {
                throw new \Exception(sprintf('Unknown query type %s', $queryType));
            }
        } catch (\Exception $e) {
            // Exclude certain ips
            if (!in_array($ip, ['185.153.198.238'])) {
                $this->logger->info(sprintf('Failed to query ip %s Err: %s', $ip, $e->getMessage()));
            }
        }

        return $this->data;
    }

    public function findCountry($ip)
    {
        $this->find($ip);

        return $this->getCountry();
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
        if (!$this->data || !$this->data->location) {
            return null;
        }

        $coordinates = new Coordinates();
        $coordinates->setCoordinates($this->data->location->longitude, $this->data->location->latitude);

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

        $this->find($ip, self::QUERY_COUNTRY);
        if ($identityLog->getCountry() != $this->getCountry()) {
            $this->statsd->increment('geoip.country');
            $this->logger->info(sprintf(
                '%s has a more accurate country.  Changing from %s to %s',
                $ip,
                $identityLog->getCountry(),
                $this->getCountry()
            ));
            $identityLog->setCountry($this->getCountry());
        }

        return $identityLog;
    }
}
