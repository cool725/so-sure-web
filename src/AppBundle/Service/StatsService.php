<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class StatsService
{
    const ROOTED_FORMAT = 'stats:rooted:%s';
    const COGNITO_FORMAT = 'stats:cognito:%s';
    const DEVICE_FORMAT = 'stats:%s:%s:%s';
    const DEVICE_MEMORY_FORMAT = 'stats:%s:%s:%s:%s';

    /** @var LoggerInterface */
    protected $logger;

    protected $redis;

    /**
     * @param                 $redis
     * @param LoggerInterface $logger
     */
    public function __construct($redis, LoggerInterface $logger)
    {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    public function quote($cognitoId, $date, $device, $memory, $found, $rooted)
    {
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
    }
}
