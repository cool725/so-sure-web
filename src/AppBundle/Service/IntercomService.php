<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;
use Doctrine\ODM\MongoDB\DocumentManager;
use Intercom\IntercomClient;

class IntercomService
{
    const KEY_INTERCOM_QUEUE = 'queue:intercom';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var IntercomClient */
    protected $client;

    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $token
     * @param                 $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $token,
        $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new IntercomClient($token, null);
        $this->redis = $redis;
    }

    public function update(User $user)
    {
        if ($user->hasSoSureEmail()) {
            return;
        }

        if ($user->hasValidPolicy()) {
            $resp = $this->updateConvert($user);
        } else {
            $resp = $this->updateLead($user);
        }

        return $resp;
    }

    public function updateConvert(User $user)
    {
        if ($user->hasSoSureEmail()) {
            return;
        }

        try {
            $resp = $this->updateUser($user);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // For leads, there should be an intercom id on the so-sure user record (set on creation)
            // However, as the record is not marked as a user, it will trigger a 404
            // So, first convert the lead, then update the user
            if ($e->getResponse()->getStatusCode() == "404") {
                $resp = $this->convertLead($user);
                $resp = $this->updateUser($user, true);
            }
        }

        return $resp;
    }

    private function convertLead(User $user)
    {
        $data = [
          "contact" => array("id" => $user->getIntercomId()),
          "user" => array("user_id" => $user->getId())
        ];

        $resp = $this->client->leads->convertLead($data);
        $this->logger->debug(sprintf('Intercom convert lead (userid %s) %s', $user->getId(), json_encode($resp)));

        return $resp;
    }

    private function updateUser(User $user, $isConverted = false)
    {
        $data = array(
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'signed_up_at' => $user->getCreated()->getTimestamp(),
        );
        if ($user->getIntercomId()) {
            $data['id'] = $user->getIntercomId();
            // user_id can only be set if the user already exists
            $data['user_id'] = $user->getId();
        }

        $policyValue = 0;
        $pot = 0;
        $connections = 0;
        foreach ($user->getValidPolicies() as $policy) {
            $policyValue += $policy->getPremium()->getYearlyPremiumPrice();
            $pot += $policy->getPotValue();
            $connections += count($policy->getConnections());
        }

        $data['custom_attributes']['premium'] = $policyValue;
        $data['custom_attributes']['pot'] = $pot;
        $data['custom_attributes']['connections'] = $connections;
        $data['custom_attributes']['promo_code'] = $user->isPreLaunch() ? 'launch' : '';

        // Only set the first time, or if the user was converted from a lead
        if (!$user->getIntercomId() || $isConverted) {
            if ($user->getIdentityLog() && $user->getIdentityLog()->getIp()) {
                $data['last_seen_ip'] = $user->getIdentityLog()->getIp();
            }
        }

        // optout
        $repo = $this->dm->getRepository(EmailOptOut::class);
        $optout = $repo->findOneBy(['email' => $user->getEmailCanonical()]);
        if ($optout) {
            $data['unsubscribed_from_emails'] = true;
        }

        $resp = $this->client->users->create($data);
        $this->logger->debug(sprintf('Intercom create user (userid %s) %s', $user->getId(), json_encode($resp)));

        $user->setIntercomId($resp->id);
        $this->dm->flush();

        return $resp;
    }
    
    private function updateLead(User $user)
    {
        $data = array(
            'email' => $user->getEmail(),
            'name' => $user->getName(),
        );
        if ($user->getIntercomId()) {
            $data['id'] = $user->getIntercomId();
        }
        $resp = $this->client->leads->create($data);
        $this->logger->debug(sprintf('Intercom create lead (userid %s) %s', $user->getId(), json_encode($resp)));

        $user->setIntercomId($resp->id);
        $this->dm->flush();

        return $resp;
    }

    public function process($max)
    {
        $count = 0;
        while ($count < $max) {
            $user = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_INTERCOM_QUEUE);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (!isset($data['userId']) || !$data['userId']) {
                    throw new \Exception(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $repo = $this->dm->getRepository(User::class);
                $user = $repo->find($data['userId']);
                if (!$user) {
                    throw new \Exception(sprintf('Unable to find userId: %s', $data['userId']));
                }

                $this->update($user);

                $count = $count + 1;
            } catch (\Exception $e) {
                if ($user) {
                    $queued = false;
                    if (isset($data['retryAttempts']) && $data['retryAttempts'] >= 0) {
                        if ($data['retryAttempts'] < 2) {
                            $this->queue(
                                $user,
                                $data['retryAttempts'] + 1
                            );
                            $queued = true;
                        }
                    } else {
                        $this->queue($user);
                        $queued = true;
                    }
                    $this->logger->error(sprintf(
                        'Error sending user %s to intercom (requeued: %s). Ex: %s',
                        $user->getId(),
                        $queued ? 'Yes' : 'No',
                        $e->getMessage()
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'Error sending user (Unknown) to intercom (requeued). Ex: %s',
                        $e->getMessage()
                    ));
                }

                throw $e;
            }
        }

        return $count;
    }

    public function queue(User $user, $retryAttempts = 0)
    {
        $data = ['userId' => $user->getId(), 'retryAttempts' => $retryAttempts];
        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    public function clearQueue()
    {
        $this->redis->del(self::KEY_INTERCOM_QUEUE);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_INTERCOM_QUEUE, 0, $max);
    }
}
