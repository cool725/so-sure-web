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
    const KEY_FORMAT = 'rate:%s:%s';
    const USER_KEY_FORMAT = 'rate:%s:%s:%s';

    const DEVICE_TYPE_ADDRESS = 'address'; // 5p / query
    const DEVICE_TYPE_IMEI = 'imei'; // 10p / query
    const DEVICE_TYPE_LOGIN = 'login';
    const DEVICE_TYPE_POLICY = 'policy';
    const DEVICE_TYPE_RESET = 'reset';
    const DEVICE_TYPE_TOKEN = 'token';
    const DEVICE_TYPE_USER_LOGIN = 'user-login';

    public static $cacheTimes = [
        self::DEVICE_TYPE_IMEI => 86400, // 1 day
        self::DEVICE_TYPE_ADDRESS => 86400, // 1 day
        self::DEVICE_TYPE_LOGIN => 3600, // 1 hour
        self::DEVICE_TYPE_POLICY => 604800, // 7 days
        self::DEVICE_TYPE_RESET => 3600, // 1 hour
        self::DEVICE_TYPE_TOKEN => 600, // 10 minutes
        self::DEVICE_TYPE_USER_LOGIN => 3600, // 10 minutes
    ];

    public static $maxRequests = [
        self::DEVICE_TYPE_IMEI => 2,
        self::DEVICE_TYPE_ADDRESS => 3,
        self::DEVICE_TYPE_LOGIN => 10,
        self::DEVICE_TYPE_POLICY => 1,
        self::DEVICE_TYPE_RESET => 2,
        self::DEVICE_TYPE_TOKEN => 10,
        self::DEVICE_TYPE_USER_LOGIN => 10,
    ];

    public static $maxIpRequests = [
        self::DEVICE_TYPE_IMEI => 14,
        self::DEVICE_TYPE_ADDRESS => 21,
        self::DEVICE_TYPE_LOGIN => 21,
        self::DEVICE_TYPE_POLICY => 50,
        self::DEVICE_TYPE_RESET => 14,
        self::DEVICE_TYPE_TOKEN => 100,
    ];

    public static $excludedIps = [
        "62.253.24.186", // runway east fin sq
        "80.169.94.194", // runway east shoreditch
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
     * Clear the rate limit
     *
     * @param string $type      TYPE_ADDRESS|TYPE_IMEI
     * @param string $ip
     * @param string $cognitoId
     *
     */
    public function clearByDevice($type, $ip, $cognitoId)
    {
        $ipKey = sprintf(self::KEY_FORMAT, $type, $ip);
        $cognitoIdKey = sprintf(self::KEY_FORMAT, $type, $cognitoId);
        $this->redis->del($ipKey);
        $this->redis->del($cognitoIdKey);
    }

    /**
     * Clear the rate limit
     *
     * @param User $user
     *
     */
    public function clearByUser($user)
    {
        $type = self::DEVICE_TYPE_USER_LOGIN;
        $userKey = sprintf(self::KEY_FORMAT, $type, $user->getId());

        $this->redis->del($userKey);
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
        $maxIpRequests = self::$maxIpRequests[$type];
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
            $this->logger->warning(sprintf('Rate limit exceeded for %s (%s/%s)', $type, $ip, $cognitoId));
            return false;
        }

        return true;
    }

    /**
     * Is the call allowed - special case for user login
     *
     * @param string $user
     *
     * @return boolean
     */
    public function allowedByUser($user)
    {
        $type = self::DEVICE_TYPE_USER_LOGIN;
        $userKey = sprintf(self::KEY_FORMAT, $type, $user->getId());

        $userRequests = $this->redis->incr($userKey);
        $maxRequests = self::$maxRequests[$type];

        $this->redis->expire($userRequests, self::$cacheTimes[$type]);

        // rate limit only for prod, or test
        if (!in_array($this->environment, ['prod', 'test'])) {
            return true;
        }

        if ($userRequests > $maxRequests) {
            $this->logger->warning(sprintf(
                'Rate limit exceeded for %s (%s/%s)',
                $type,
                $user->getName(),
                $user->getId()
            ));

            return false;
        }

        return true;
    }

    /**
     * Is the call allowed
     *
     * @param string $type TYPE_ADDRESS|TYPE_IMEI
     * @param string $ip
     *
     * @return boolean
     */
    public function allowedByIp($type, $ip)
    {
        $ipKey = sprintf(self::KEY_FORMAT, $type, $ip);

        $ipRequests = $this->redis->incr($ipKey);
        $maxIpRequests = self::$maxIpRequests[$type];

        $this->redis->expire($ipKey, self::$cacheTimes[$type]);

        // ignore rate limiting for some ips
        if (in_array($ip, self::$excludedIps)) {
            return true;
        }

        // rate limit only for prod, or test
        if (!in_array($this->environment, ['prod', 'test'])) {
            return true;
        }

        // ip should always be higher as may be multiple users behind a nat
        if ($ipRequests > $maxIpRequests) {
            $this->logger->warning(sprintf('Rate limit exceeded for %s (%s)', $type, $ip));
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
