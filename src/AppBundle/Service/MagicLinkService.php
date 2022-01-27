<?php

namespace AppBundle\Service;

use AppBundle\Document\User;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

use Predis\Client;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Hmac\Sha256;

class MagicLinkService
{
    const MAGICLINK_KEY = 'MagicLink:%s';
    const MAGICLINK_TIMEOUT = 60;
    const MAGICLINK_SECRET = 'sosureautologinismagic';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var Client */
    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param Client          $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        Client $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    public function setMagicLink($user)
    {
        $token = (new Builder())->setIssuedAt(time());
        $token->setId(uniqid('', true));
        $token->set('email', $user->getEmailCanonical());
        $characters = '0123456789';
        $randomCode = '';
        for ($i = 0; $i < 6; $i++) {
            $randomCode .= $characters[rand(0, 9)];
        }
        $token->set('randomCode', $randomCode);

        $token = (string) $token->sign(new Sha256(), self::MAGICLINK_SECRET)->getToken();

        $key = sprintf(self::MAGICLINK_KEY, $token);
        $this->redis->setex($key, self::MAGICLINK_TIMEOUT, $user->getId());

        return $token;
    }

    public function verifyMagicLink($code, $email)
    {

        $key = sprintf(self::MAGICLINK_KEY, $code);
        $userId = $this->redis->get($key);

        if ($userId === null) {
            $this->logger->error(sprintf('Failed to find valid magic code for key %s, with value %s ', $key, $code));
            throw new \InvalidArgumentException("Magic Link is invalid");
        }

        $token = (new Parser())->parse((string) $code);
        if (!$token->verify(new Sha256(), self::MAGICLINK_SECRET)) {
            $this->logger->error(sprintf('Failed to validate %s for email %s', $code, $email));
            throw new \InvalidArgumentException("JWT signature is invalid");
        }

        if ($token->getClaim('email') !== $email) {
            $this->logger->error(sprintf(
                'Failed to validate user email identity %s %s',
                $email,
                json_encode($token->getClaims())
            ));

            throw new \InvalidArgumentException("User email doesn't match magic code claims");
        }

        $this->redis->del($key);
        return $userId;
    }
}
