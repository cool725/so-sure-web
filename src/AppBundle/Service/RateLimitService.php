<?php
namespace AppBundle\Service;

use AppBundle\Document\Feature;
use Predis\Client;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Request;

class RateLimitService
{
    const KEY_FORMAT = 'rate:%s:%s';
    const USER_KEY_FORMAT = 'rate:%s:%s:%s';
    const REPLAY_KEY_FORMAT = 'replay:%s';

    const DEVICE_TYPE_ADDRESS = 'address'; // 5p / query
    const DEVICE_TYPE_BACS = 'bacs'; // 5p / query
    const DEVICE_TYPE_IMEI = 'imei'; // 2p / query
    const DEVICE_TYPE_SERIAL = 'serial'; // 5p / query
    const DEVICE_TYPE_LOGIN = 'login';
    const DEVICE_TYPE_POLICY = 'policy';
    const DEVICE_TYPE_RESET = 'reset';
    const DEVICE_TYPE_TOKEN = 'token';
    const DEVICE_TYPE_USER_LOGIN = 'user-login';
    const DEVICE_TYPE_OPT = 'optout';
    const DEVICE_TYPE_INTERCOM_DPA = 'intercom-dpa';

    public static $cacheTimes = [
        self::DEVICE_TYPE_IMEI => 86400, // 1 day
        self::DEVICE_TYPE_SERIAL => 86400, // 1 day
        self::DEVICE_TYPE_ADDRESS => 86400, // 1 day
        self::DEVICE_TYPE_BACS => 86400, // 1 day
        self::DEVICE_TYPE_LOGIN => 3600, // 1 hour
        self::DEVICE_TYPE_POLICY => 604800, // 7 days
        self::DEVICE_TYPE_RESET => 3600, // 1 hour
        self::DEVICE_TYPE_TOKEN => 600, // 10 minutes
        self::DEVICE_TYPE_USER_LOGIN => 3600, // 1 hour
        self::DEVICE_TYPE_OPT => 600, // 10 minutes
        self::DEVICE_TYPE_INTERCOM_DPA => 600,
        'replay' => 60, // 1 minute
    ];

    public static $maxRequests = [
        self::DEVICE_TYPE_IMEI => 2,
        self::DEVICE_TYPE_SERIAL => 2,
        self::DEVICE_TYPE_ADDRESS => 3,
        self::DEVICE_TYPE_BACS => 3,
        self::DEVICE_TYPE_LOGIN => 10,
        self::DEVICE_TYPE_POLICY => 1,
        self::DEVICE_TYPE_RESET => 2,
        self::DEVICE_TYPE_TOKEN => 10,
        self::DEVICE_TYPE_USER_LOGIN => 10,
        self::DEVICE_TYPE_OPT => 3,
        self::DEVICE_TYPE_INTERCOM_DPA => 20,
    ];

    public static $maxIpRequests = [
        self::DEVICE_TYPE_IMEI => 14,
        self::DEVICE_TYPE_SERIAL => 14,
        self::DEVICE_TYPE_ADDRESS => 21,
        self::DEVICE_TYPE_BACS => 21,
        self::DEVICE_TYPE_LOGIN => 21,
        self::DEVICE_TYPE_POLICY => 50,
        self::DEVICE_TYPE_RESET => 14,
        self::DEVICE_TYPE_TOKEN => 100,
        self::DEVICE_TYPE_OPT => 14,
    ];

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client */
    protected $redis;

    protected $environment;

    /** @var FeatureService */
    protected $featureService;

    protected $excludedIps;

    /**
     * @param Client          $redis
     * @param LoggerInterface $logger
     * @param string          $environment
     * @param FeatureService  $featureService
     * @param array           $excludedIps
     */
    public function __construct(
        Client $redis,
        LoggerInterface $logger,
        $environment,
        FeatureService $featureService,
        $excludedIps
    ) {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->environment = $environment;
        $this->featureService = $featureService;
        $this->excludedIps = $excludedIps;
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
    public function clearByUser(User $user)
    {
        return $this->clearByUserId($user->getId(), self::DEVICE_TYPE_USER_LOGIN);
    }

    /**
     * Clear the rate limit
     *
     * @param string $userId
     * @param string $type
     *
     */
    public function clearByUserId($userId, $type)
    {
        $userKey = sprintf(self::KEY_FORMAT, $type, $userId);

        $this->redis->del($userKey);
    }

    public function clearByType($type)
    {
        if ($type == 'all') {
            $type = '*';
        }
        $search = sprintf(self::KEY_FORMAT, $type, '*');
        foreach ($this->redis->keys($search) as $key) {
            $data[$key] = $this->redis->del($key);
        }
    }

    public function show($type)
    {
        $data = [];
        if ($type == 'all') {
            $type = '*';
        }
        $search = sprintf(self::KEY_FORMAT, $type, '*');
        foreach ($this->redis->keys($search) as $key) {
            $data[$key] = $this->redis->get($key);
        }

        return $data;
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
    public function allowedByDevice($type, $ip, $cognitoId = null)
    {
        if (!$this->featureService->isEnabled(Feature::FEATURE_RATE_LIMITING)) {
            return true;
        }

        $maxIpRequests = self::$maxIpRequests[$type];
        $maxCognitoRequests = self::$maxRequests[$type];

        $ipKey = sprintf(self::KEY_FORMAT, $type, $ip);
        $cognitoIdKey = sprintf(self::KEY_FORMAT, $type, $cognitoId);

        $ipRequests = $this->redis->incr($ipKey);
        $this->redis->expire($ipKey, self::$cacheTimes[$type]);

        if ($cognitoId) {
            $cognitoRequests = $this->redis->incr($cognitoIdKey);
            $this->redis->expire($cognitoIdKey, self::$cacheTimes[$type]);
        } else {
            // ignore cognito if not set
            $cognitoRequests = 0;
        }

        // ignore rate limiting for some ips
        if (in_array($ip, $this->excludedIps)) {
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
     * @param User $user
     *
     * @return boolean
     */
    public function allowedByUser(User $user)
    {
        return $this->allowedByUserId($user->getId(), self::DEVICE_TYPE_USER_LOGIN);
    }

    /**
     * Is the call allowed - special case for user login & intercom
     *
     * @param string $userId
     * @param string $type
     *
     * @return boolean
     */
    public function allowedByUserId($userId, $type)
    {
        if (!$this->featureService->isEnabled(Feature::FEATURE_RATE_LIMITING)) {
            return true;
        }

        $userKey = sprintf(self::KEY_FORMAT, $type, $userId);
        $userRequests = $this->redis->incr($userKey);
        $maxRequests = self::$maxRequests[$type];

        $this->redis->expire($userRequests, self::$cacheTimes[$type]);

        // rate limit only for prod, or test
        if (!in_array($this->environment, ['prod', 'test'])) {
            return true;
        }

        if ($userRequests > $maxRequests) {
            $this->logger->warning(sprintf(
                'Rate limit exceeded for %s (%s)',
                $type,
                $userId
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
        if (in_array($ip, $this->excludedIps)) {
            return true;
        }

        // rate limit only for prod, or test
        if (!in_array($this->environment, ['prod', 'test'])) {
            return true;
        }

        if ($ip != filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE |  FILTER_FLAG_NO_RES_RANGE
        )) {
            $this->logger->warning(sprintf('Rate limit is using a private ip range (%s)', $ip));
        }

        // ip should always be higher as may be multiple users behind a nat
        if ($ipRequests > $maxIpRequests) {
            $this->logger->warning(sprintf('Rate limit exceeded for %s (%s)', $type, $ip));
            return false;
        }

        return true;
    }

    /**
     * Is the call allowed
     *
     * @param string  $cognitoId
     * @param Request $request
     *
     * @return boolean
     */
    public function replay($cognitoId, $request)
    {
        $body = json_encode(json_decode($request->getContent(), true)['body']);
        $contents = sprintf('%s%s', $request->getUri(), $body);

        return $this->replayData($cognitoId, $contents);
    }

    public function replayData($id, $contents)
    {
        $replayKey = sprintf(self::REPLAY_KEY_FORMAT, $id);

        $shaContents = sha1($contents);
        $this->logger->debug(sprintf('Replay Check %s -> sha1(%s)', $replayKey, $contents));
        if ($this->redis->hexists($replayKey, $shaContents) == 1) {
            return false;
        }

        $this->redis->hset($replayKey, $shaContents, 1);
        $this->redis->expire($replayKey, self::$cacheTimes['replay']);

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
