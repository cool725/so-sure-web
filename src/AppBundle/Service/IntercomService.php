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
    const SECURE_WEB = 'web';
    const SECURE_ANDROID = 'android';
    const SECURE_IOS = 'ios';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var IntercomClient */
    protected $client;

    protected $redis;

    protected $secure;
    protected $secureAndroid;
    protected $secureIOS;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $token
     * @param                 $redis
     * @param string          $secure
     * @param string          $secureAndroid
     * @param string          $secureIOS
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $token,
        $redis,
        $secure,
        $secureAndroid,
        $secureIOS
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new IntercomClient($token, null);
        $this->redis = $redis;
        $this->secure = $secure;
        $this->secureAndroid = $secureAndroid;
        $this->secureIOS = $secureIOS;
    }

    public function update(User $user, $allowSoSure = false, $undelete = false)
    {
        if ($user->hasSoSureEmail() && !$allowSoSure) {
            return ['skipped' => true];
        }

        if (!$undelete && $this->isDeleted($user)) {
            return ['deleted' => true];
        }

        $converted = false;
        if ($this->leadExists($user)) {
            $this->convertLead($user);
            $converted = true;
        }
        $resp = $this->updateUser($user, $converted);

        return $resp;
    }

    private function isDeleted(User $user)
    {
        // Record must exist before it can be considered deleted
        if (!$user->getIntercomId()) {
            return false;
        }

        if ($this->userExists($user) || $this->leadExists($user)) {
            return false;
        }

        return true;
    }

    private function leadExists(User $user)
    {
        if (!$user->getIntercomId()) {
            return false;
        }

        try {
            $resp = $this->client->leads->getLead($user->getIntercomId());
            $this->logger->info(sprintf('getLead %s %s', $user->getEmail(), json_encode($resp)));

            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() == "404") {
                return false;
            }
            // print_r($e->getResponse()->getBody()->getContents());

            throw $e;
        }
    }

    private function userExists(User $user)
    {
        if (!$user->getIntercomId()) {
            return false;
        }

        try {
            $resp = $this->client->users->getUser($user->getIntercomId());
            $this->logger->info(sprintf('getUser %s %s', $user->getEmail(), json_encode($resp)));

            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() == "404") {
                return false;
            }

            throw $e;
        }
    }

    public function convertLead(User $user)
    {
        $data = [
          "contact" => array("id" => $user->getIntercomId()),
          "user" => array("user_id" => $user->getId()),
        ];

        $resp = $this->client->leads->convertLead($data);
        $this->logger->debug(sprintf('Intercom convert lead (userid %s) %s', $user->getId(), json_encode($resp)));

        return $resp;
    }

    public function getApiUserHash(User $user = null)
    {
        if (!$user || !$user->getId()) {
            return null;
        }

        return [
            'android_hash' => $this->getUserHash($user, self::SECURE_ANDROID),
            'ios_hash' => $this->getUserHash($user, self::SECURE_IOS),
        ];
    }

    public function getUserHash(User $user = null, $secureType = null)
    {
        $secure = null;
        if ($secureType == self::SECURE_WEB) {
            $secure = $this->secure;
        } elseif ($secureType == self::SECURE_ANDROID) {
            $secure = $this->secureAndroid;
        } elseif ($secureType == self::SECURE_IOS) {
            $secure = $this->secureIOS;
        } else {
            throw new \Exception('Unknown secure type');
        }

        if ($user && $user->getId()) {
            return hash_hmac('sha256', $user->getId(), $secure);
        }

        return null;
    }

    private function updateUser(User $user, $isConverted = false)
    {
        $data = array(
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'signed_up_at' => $user->getCreated()->getTimestamp(),
            'user_id' => $user->getId(),
        );
        if ($user->getIntercomId()) {
            $data['id'] = $user->getIntercomId();
        }

        $policyValue = 0;
        $pot = 0;
        $connections = 0;
        foreach ($user->getValidPolicies() as $policy) {
            if ($policy->isValidPolicy()) {
                $policyValue += $policy->getPremium()->getYearlyPremiumPrice();
                $pot += $policy->getPotValue();
                $connections += count($policy->getConnections());
            }
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
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optedOut = $emailOptOutRepo->isOptedOut($user->email, EmailOptOut::OPTOUT_CAT_AQUIRE) ||
            $emailOptOutRepo->isOptedOut($user->email, EmailOptOut::OPTOUT_CAT_RETAIN);
        if ($optedOut) {
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
    
    public function unsubscribes()
    {
        $this->unsubscribeLeads();
        $this->unsubscribeUsers();
    }

    private function unsubscribeLeads()
    {
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $page = 1;
        $pages = 1;
        while ($page <= $pages) {
            print sprintf('Checking Leads - page %d%s', $page, PHP_EOL);
            $resp = $this->client->leads->getLeads(['page' => $page]);
            $page++;
            $pages = $resp->pages->total_pages;
            
            foreach ($resp->contacts as $lead) {
                $optedOut = $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_AQUIRE) ||
                    $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                if ($lead->unsubscribed_from_emails && !$optedOut) {
                    $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_AQUIRE);
                    $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                    print sprintf("Added optout for %s\n", $lead->email);
                }
            }
            $this->dm->flush();
        }
    }

    private function unsubscribeUsers()
    {
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $page = 1;
        $pages = 1;
        while ($page <= $pages) {
            print sprintf('Checking Users - page %d%s', $page, PHP_EOL);
            $resp = $this->client->users->getUsers(['page' => $page]);
            $page++;
            $pages = $resp->pages->total_pages;
            foreach ($resp->users as $user) {
                $optedOut = $emailOptOutRepo->isOptedOut($user->email, EmailOptOut::OPTOUT_CAT_AQUIRE) ||
                    $emailOptOutRepo->isOptedOut($user->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                if ($user->unsubscribed_from_emails && !$optedOut) {
                    // Webhook callback from intercom issue
                    $this->addEmailOptOut($user->email, EmailOptOut::OPTOUT_CAT_AQUIRE);
                    $this->addEmailOptOut($user->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                    print sprintf("Added optout for %s\n", $user->email);
                } elseif (!$user->unsubscribed_from_emails && $optedOut) {
                    // sosure user listener -> queue -> intercom update issue
                    $sosureUser = $userRepo->findOneBy(['emailCanonical' => strtolower($user->email)]);
                    $this->updateUser($sosureUser);
                    print sprintf("Resync intercom user for %s\n", $user->email);
                }
            }
            $this->dm->flush();
        }
    }

    private function addEmailOptOut($email, $category)
    {
        $optout = new EmailOptOut();
        $optout->setCategory($category);
        $optout->setEmail($email);
        $this->dm->persist($optout);
    }
}
