<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class RateLimitService
{
    const IP_ADDRESS_MULTIPLIER = 7; // at most 7 users per ip address
    const KEY_FORMAT = 'rate:%s:%s';
    const USER_KEY_FORMAT = 'rate:%s:%s:%s';

    const DEVICE_TYPE_ADDRESS = 'address'; // 5p / query
    const DEVICE_TYPE_IMEI = 'imei'; // 10p / query
    const DEVICE_TYPE_LOGIN = 'login';
    const DEVICE_TYPE_POLICY = 'policy';

    public static $cacheTimes = [
        self::DEVICE_TYPE_IMEI => 86400, // 1 day
        self::DEVICE_TYPE_ADDRESS => 86400, // 1 day
        self::DEVICE_TYPE_LOGIN => 3600, // 1 hour
        self::DEVICE_TYPE_POLICY => 604800, // 7 days
    ];

    public static $maxRequests = [
        self::DEVICE_TYPE_IMEI => 2,
        self::DEVICE_TYPE_ADDRESS => 3,
        self::DEVICE_TYPE_LOGIN => 3,
        self::DEVICE_TYPE_POLICY => 8,
    ];

    public static $excludedIps = [
        "62.253.24.186", // runway east
        "86.3.184.79", // patrick home
    ];

    /** @var LoggerInterface */
    protected $logger;

    protected $redis;

    protected $environment;

    /**
     * @param                 $redis
     * @param LoggerInterface $logger
     * @param string          $environment
     */
    public function __construct($redis, LoggerInterface $logger, $environment)
    {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->environment = $environment;
    }

    /**
     * Is the call allowed
     *
     * @param string $type      TYPE_ADDRESS|TYPE_IMEI
     * @param string $ip
     * @param string $cognitoId
     *
     * @return boolean
     */
    public function allowedByDevice($type, $ip, $cognitoId)
    {
        $ipKey = sprintf(self::KEY_FORMAT, $type, $ip);
        $cognitoIdKey = sprintf(self::KEY_FORMAT, $type, $cognitoId);

        $ipRequests = $this->redis->incr($ipKey);
        $maxIpRequests = self::$maxRequests[$type] * self::IP_ADDRESS_MULTIPLIER;
        $cognitoRequests = $this->redis->incr($cognitoIdKey);
        $maxCognitoRequests = self::$maxRequests[$type];

        $this->redis->expire($ipKey, self::$cacheTimes[$type]);
        $this->redis->expire($cognitoIdKey, self::$cacheTimes[$type]);

        // ignore rate limiting for some ips
        if (in_array($ip, self::$excludedIps)) {
            return true;
        }

        // rate limit only for prod, or test
        if (!in_array($this->environment, ['prod', 'test'])) {
            return true;
        }

        // ip should always be higher as may be multiple users behind a nat
        if ($ipRequests > $maxIpRequests || $cognitoRequests > $maxCognitoRequests) {
            return false;
        }

        return true;
    }

    /**
     * Unused for now, but may need similiar functionality in the future...
     * Is the call allowed for a specific key
     *
     * @param User    $user
     * @param string  $type
     * @param string  $data          What is unique about the data - email/mobile
     * @param boolean $increment
     * @param boolean $slidingWindow Increment the expire date every time accessed?
     *
     * @return boolean
     *
    public function allowedByUser(User $user, $type, $data, $increment = true, $slidingWindow = true)
    {
        $redisKey = sprintf(self::USER_KEY_FORMAT, $user->getId(), $type, $data);
        $maxRequests = self::$maxRequests[$type];
        $cacheTime = self::$cacheTimes[$type];
        $maxRequests = self::$maxRequests[$type];

        if (!$increment) {
            $requests = $this->redis->get($redisKey);
            if ($request) {
                return $requests <= $maxRequests;
            } else {
                return true;
            }
        }

        $requests = $this->redis->incr($redisKey);
        if ($cacheTime) {
            // Only set expire if it doesn't exist for non-sliding windows
            if ($slidingWindow || $this->redis->ttl($redisKey) == -1) {
                $this->redis->expire($redisKey, $cacheTime);
            }
        }

        return $requests <= $maxRequests;
    }
    */
}
