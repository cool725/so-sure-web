<?php
namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Stats;
use AppBundle\Document\DateTrait;

class StatsService
{
    use DateTrait;

    const ROOTED_FORMAT = 'stats:rooted:%s';
    const COGNITO_FORMAT = 'stats:cognito:%s';
    const DEVICE_FORMAT = 'stats:%s:%s:%s';
    const DEVICE_MEMORY_FORMAT = 'stats:%s:%s:%s:%s';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client  */
    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param Client          $redis
     * @param LoggerInterface $logger
     */
    public function __construct(DocumentManager $dm, Client $redis, LoggerInterface $logger)
    {
        $this->dm = $dm;
        $this->redis = $redis;
        $this->logger = $logger;
    }

    public function quote($cognitoId, $date, $device, $memory, $found, $rooted)
    {
        try {
            $queryName = 'query';
            if (!$found) {
                $queryName = 'missing';
            }

            // in theory should only be 1 device / cognito id
            // so only incr query counts, etc once
            $cognitoKey = sprintf(self::COGNITO_FORMAT, $cognitoId);
            if ($this->redis->incr($cognitoKey) == 1) {
                $deviceKey = sprintf(self::DEVICE_FORMAT, $date->format('Y-m-d'), $queryName, $device);
                $this->redis->incr($deviceKey);

                $deviceMemoryKey = sprintf(
                    self::DEVICE_MEMORY_FORMAT,
                    $date->format('Y-m-d'),
                    $queryName,
                    $device,
                    $memory
                );
                $this->redis->incr($deviceMemoryKey);

                if ($rooted) {
                    $rootedKey = sprintf(self::ROOTED_FORMAT, $device);
                    $this->redis->incr($rootedKey);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in stats quote Ex: %s', $e->getMessage()));
        }
    }

    public function set($name, $date, $value, $overwrite = true)
    {
        $repo = $this->dm->getRepository(Stats::class);
        /** @var Stats $stat */
        $stat = $repo->findOneBy(['name' => $name, 'date' => $this->startOfDay($date)]);
        if (!$stat) {
            $stat = new Stats();
            $stat->setDate($this->startOfDay($date));
            $stat->setName($name);
            $stat->setValue($value);
            $this->dm->persist($stat);
        } elseif ($overwrite) {
            $stat->setValue($value);
        }

        $this->dm->flush();
    }

    public function increment($name, $date = null, $overwrite = false)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $repo = $this->dm->getRepository(Stats::class);
        /** @var Stats $stat */
        $stat = $repo->findOneBy(['name' => $name, 'date' => $this->startOfDay($date)]);
        if (!$stat) {
            $stat = new Stats();
            $stat->setDate($this->startOfDay($date));
            $stat->setName($name);
            $stat->setValue(1);
            $this->dm->persist($stat);
        } elseif ($overwrite) {
            $stat->setValue($stat->getValue() + 1);
        }

        $this->dm->flush();
    }

    public function decrement($name, $date, $overwrite = false)
    {
        $repo = $this->dm->getRepository(Stats::class);
        /** @var Stats $stat */
        $stat = $repo->findOneBy(['name' => $name, 'date' => $this->startOfDay($date)]);
        if (!$stat) {
            $stat = new Stats();
            $stat->setDate($date);
            $stat->setName($this->startOfDay($date));
            $stat->setValue(-1);
            $this->dm->persist($stat);
        } elseif ($overwrite) {
            $stat->setValue($stat->getValue() - 1);
        }

        $this->dm->flush();
    }
}
