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
    const CACHE_TIME = 84600; // 1 day
    const KEY_FORMAT = 'rate:%s:%s';
    const TYPE_ADDRESS = 'address'; // 5p / query
    const TYPE_IMEI = 'imei'; // 10p / query

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

    /**
     * Is the call allowed
     *
     * @param string $type      TYPE_ADDRESS|TYPE_IMEI
     * @param string $ip
     * @param string $cognitoId
     *
     * @return boolean
     */
    public function allowed($type, $ip, $cognitoId)
    {
        $ipKey = sprintf(self::KEY_FORMAT, $type, $ip);
        $cognitoIdKey = sprintf(self::KEY_FORMAT, $type, $cognitoId);

        $allowed = true;

        $ipRequests = $this->redis->incr($ipKey);
        $maxIpRequests = $this->getMaxAllowedRequests($type) * self::IP_ADDRESS_MULTIPLIER;
        $cognitoRequests = $this->redis->incr($cognitoIdKey);
        $maxCognitoRequests = $this->getMaxAllowedRequests($type);

        $this->redis->expire($ipKey, self::CACHE_TIME);
        $this->redis->expire($cognitoIdKey, self::CACHE_TIME);

        // ignore rate limiting from runway easy
        if ($ip == "62.253.24.186") {
            return true;
        }

        // ip should always be higher as may be multiple users behind a nat
        if ($ipRequests > $maxIpRequests || $cognitoRequests > $maxCognitoRequests) {
            $allowed = false;
        }

        return $allowed;
    }
    
    public function getMaxAllowedRequests($type)
    {
        if ($type == self::TYPE_ADDRESS) {
            return 3;
        } elseif ($type == self::TYPE_IMEI) {
            return 2;
        }

        return 0;
    }
}
