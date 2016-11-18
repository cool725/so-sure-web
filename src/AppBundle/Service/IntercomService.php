<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;

use Doctrine\ODM\MongoDB\DocumentManager;
use Intercom\IntercomClient;

class IntercomService
{
    const KEY_INTERCOM_QUEUE = 'queue:intercom';

    const SECURE_WEB = 'web';
    const SECURE_ANDROID = 'android';
    const SECURE_IOS = 'ios';

    const QUEUE_USER = 'user';

    const QUEUE_EVENT_POLICY_CREATED = 'policy-created';
    const QUEUE_EVENT_POLICY_CANCELLED = 'policy-cancelled';

    /*
    const QUEUE_EVENT_INVITATION_ACCEPTED = 'invitation-accepted';
    const QUEUE_EVENT_INVITATION_CANCELLED = 'invitation-cancelled';
    const QUEUE_EVENT_INVITATION_INVITED = 'invitation-invited';
    const QUEUE_EVENT_INVITATION_PENDING = 'invitation-pending';
    const QUEUE_EVENT_INVITATION_RECEIVED = 'invitation-received';
    const QUEUE_EVENT_INVITATION_REJECTED = 'invitation-rejected';
    const QUEUE_EVENT_INVITATION_REINVITED = 'invitation-reinvited';
    */
    const QUEUE_EVENT_INVITATION_PENDING = 'invitation-pending';

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
        try {
            $resp = $this->client->leads->getLeads(['email' => $user->getEmail()]);
            return count($resp->contacts) > 0;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            throw $e;
        }
    }

    /*
    public function getLeadByEmail(User $user)
    {
        try {
            $resp = $this->client->leads->getLeads(['email' => $user->getEmail()]);
            $this->logger->info(sprintf('getLead %s %s', $user->getEmail(), json_encode($resp)));

            return $resp;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() == "404") {
                return false;
            }
            // print_r($e->getResponse()->getBody()->getContents());

            throw $e;
        }
    }
    */

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
        $results = [];
        $resp = $this->client->leads->getLeads(['email' => $user->getEmail()]);
        $results[] = $resp;
        foreach ($resp->contacts as $lead) {
            $data = [
              "contact" => array("id" => $lead->id),
              "user" => array("user_id" => $user->getId()),
            ];

            $resp = $this->client->leads->convertLead($data);
            $this->logger->debug(sprintf('Intercom convert lead (userid %s) %s', $user->getId(), json_encode($resp)));
            $results[] = $resp;
        }

        return $results;
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

        $data['custom_attributes']['Premium'] = $policyValue;
        $data['custom_attributes']['Pot'] = $pot;
        $data['custom_attributes']['Connections'] = $connections;
        $data['custom_attributes']['Promo Code'] = $user->getCurrentPolicy() ?
            $user->getCurrentPolicy()->getPromoCode() :
            '';
        $data['custom_attributes']['Pending Invites'] = count($user->getUnprocessedReceivedInvitations());

        // Only set the first time, or if the user was converted from a lead
        if (!$user->getIntercomId() || $isConverted) {
            if ($user->getIdentityLog() && $user->getIdentityLog()->getIp()) {
                $data['last_seen_ip'] = $user->getIdentityLog()->getIp();
            }
        }

        // optout
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optedOut = $emailOptOutRepo->isOptedOut($user->getEmail(), EmailOptOut::OPTOUT_CAT_AQUIRE) ||
            $emailOptOutRepo->isOptedOut($user->getEmail(), EmailOptOut::OPTOUT_CAT_RETAIN);
        if ($optedOut) {
            $data['unsubscribed_from_emails'] = true;
        }

        $resp = $this->client->users->create($data);
        $this->logger->debug(sprintf('Intercom create user (userid %s) %s', $user->getId(), json_encode($resp)));

        $user->setIntercomId($resp->id);
        $this->dm->flush();

        return $resp;
    }

    public function updateLead(Lead $lead)
    {
        $data = array(
            'email' => $lead->getEmail(),
        );
        if ($lead->getIntercomId()) {
            $data['id'] = $lead->getIntercomId();
        }
        $resp = $this->client->leads->create($data);
        $this->logger->debug(sprintf('Intercom create lead (userid %s) %s', $lead->getId(), json_encode($resp)));

        $lead->setIntercomId($resp->id);
        $this->dm->flush();

        return $resp;
    }

    public function sendPolicyEvent(Policy $policy, $event)
    {
        $now = new \DateTime();
        $data = [
            'event_name' => $event,
            'created_at' => $now->getTimestamp(),
            'id' => $policy->getUser()->getIntercomId(),
            'user_id' => $policy->getUser()->getId(),
        ];
        if ($event == self::QUEUE_EVENT_POLICY_CANCELLED) {
            $data['metadata']['Cancelled Reason'] = $policy->getCancelledReason();
        }
        $resp = $this->client->events->create($data);
        $this->logger->debug(sprintf('Intercom create event (%s) %s', $event, json_encode($resp)));
    }

    public function sendInvitationEvent(Invitation $invitation, $event)
    {
        $now = new \DateTime();
        $data = [
            'event_name' => $event,
            'created_at' => $now->getTimestamp(),
        ];

        // QUEUE_EVENT_INVITATION_PENDING
        $useInviter = false;
        /*
        if ($event == self::QUEUE_EVENT_INVITATION_CANCELLED ||
            $event == self::QUEUE_EVENT_INVITATION_INVITED ||
            $event == self::QUEUE_EVENT_INVITATION_REINVITED) {
            $useInviter = true;
        } elseif ($event == self::QUEUE_EVENT_INVITATION_RECEIVED ||
            $event == self::QUEUE_EVENT_INVITATION_PENDING ||
            $event == self::QUEUE_EVENT_INVITATION_ACCEPTED ||
            $event == self::QUEUE_EVENT_INVITATION_REJECTED) {
            $useInviter = false;
        }
        */

        if ($useInviter && !$this->isDeleted($invitation->getInviter())) {
            $data['id'] = $invitation->getInviter()->getIntercomId();
            $data['user_id'] = $invitation->getInviter()->getId();
            $data['metadata']['Invitee Name'] = $invitation->getInvitee()->getName();
        } elseif (!$useInviter && !$this->isDeleted($invitation->getInvitee())) {
            $data['id'] = $invitation->getInvitee()->getIntercomId();
            $data['user_id'] = $invitation->getInvitee()->getId();
            $data['metadata']['Inviter Name'] = $invitation->getInviter()->getName();
        } else {
            $this->logger->debug(sprintf('Skipping Intercom create event (%s) as user deleted', $event));
            return;
        }

        $resp = $this->client->events->create($data);
        $this->logger->debug(sprintf('Intercom create event (%s) %s', $event, json_encode($resp)));
    }

    public function process($max)
    {
        $count = 0;
        while ($count < $max) {
            $user = null;
            $data = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_INTERCOM_QUEUE);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (isset($data['action'])) {
                    $action = $data['action'];
                } else {
                    // legacy before action was used.  can be removed soon after
                    $action = self::QUEUE_USER;
                }

                if ($action == self::QUEUE_USER) {
                    if (!isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->update($this->getUser($data['userId']));
                } elseif ($action == self::QUEUE_EVENT_POLICY_CREATED ||
                          $action == self::QUEUE_EVENT_POLICY_CANCELLED) {
                    if (!isset($data['policyId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendPolicyEvent($this->getPolicy($data['policyId']), $action);
                } elseif ($action == self::QUEUE_EVENT_INVITATION_PENDING) {
                    /*
                          $action == self::QUEUE_EVENT_INVITATION_ACCEPTED ||
                          $action == self::QUEUE_EVENT_INVITATION_CANCELLED ||
                          $action == self::QUEUE_EVENT_INVITATION_INVITED ||
                          $action == self::QUEUE_EVENT_INVITATION_PENDING ||
                          $action == self::QUEUE_EVENT_INVITATION_RECEIVED ||
                          $action == self::QUEUE_EVENT_INVITATION_REINVITED ||
                          $action == self::QUEUE_EVENT_INVITATION_REJECTED) {
                    */
                    if (!isset($data['invitationId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendInvitationEvent($this->getInvitation($data['invitationId']), $action);
                } else {
                    throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $count = $count + 1;
            } catch (\InvalidArgumentException $e) {
                $this->logger->error(sprintf(
                    'Error processing Intercom queue message %s. Ex: %s',
                    json_encode($data),
                    $e->getMessage()
                ));
            } catch (\Exception $e) {
                if (isset($data['retryAttempts']) && $data['retryAttempts'] < 2) {
                    $data['retryAttempts'] += 1;
                    $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
                } else {
                    $this->logger->error(sprintf(
                        'Error (retry exceeded) sending message to Intercom %s. Ex: %s',
                        json_encode($data),
                        $e->getMessage()
                    ));
                }
            }
        }

        return $count;
    }

    private function getUser($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing userId');
        }
        $repo = $this->dm->getRepository(User::class);
        $user = $repo->find($id);
        if (!$user) {
            throw new \InvalidArgumentException(sprintf('Unable to find userId: %s', $id));
        }

        return $user;
    }

    private function getPolicy($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing policyId');
        }
        $repo = $this->dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw new \InvalidArgumentException(sprintf('Unable to find policyId: %s', $id));
        }

        return $policy;
    }

    private function getInvitation($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing invitationId');
        }
        $repo = $this->dm->getRepository(Invitation::class);
        $invitation = $repo->find($id);
        if (!$invitation) {
            throw new \InvalidArgumentException(sprintf('Unable to find invitationId: %s', $id));
        }

        return $invitation;
    }

    public function queue(User $user, $retryAttempts = 0)
    {
        $data = [
            'action' => self::QUEUE_USER,
            'userId' => $user->getId(),
            'retryAttempts' => $retryAttempts
        ];
        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    public function queuePolicy(Policy $policy, $event, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'policyId' => $policy->getId(),
            'retryAttempts' => $retryAttempts
        ];
        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    public function queueInvitation(Invitation $invitation, $event, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'invitationId' => $invitation->getId(),
            'retryAttempts' => $retryAttempts
        ];
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
        return array_merge($this->unsubscribeLeads(), $this->unsubscribeUsers());
    }

    private function unsubscribeLeads()
    {
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $output = [];
        $page = 1;
        $pages = 1;
        while ($page <= $pages) {
            $output[] = sprintf('Checking Leads - page %d', $page);
            $resp = $this->client->leads->getLeads(['page' => $page]);
            $page++;
            $pages = $resp->pages->total_pages;
            
            foreach ($resp->contacts as $lead) {
                $optedOut = $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_AQUIRE) ||
                    $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                if ($lead->unsubscribed_from_emails && !$optedOut) {
                    $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_AQUIRE);
                    $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                    $output[] = sprintf("Added optout for %s", $lead->email);
                }
            }
            $this->dm->flush();
        }

        return $output;
    }

    private function unsubscribeUsers()
    {
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $output = [];
        $page = 1;
        $pages = 1;
        while ($page <= $pages) {
            $output[] = sprintf('Checking Users - page %d', $page);
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
                    $output[] = sprintf("Added optout for %s", $user->email);
                } elseif (!$user->unsubscribed_from_emails && $optedOut) {
                    // sosure user listener -> queue -> intercom update issue
                    $sosureUser = $userRepo->findOneBy(['emailCanonical' => strtolower($user->email)]);
                    $this->updateUser($sosureUser);
                    $output[] = sprintf("Resync intercom user for %s", $user->email);
                }
            }
            $this->dm->flush();
        }

        return $output;
    }

    private function addEmailOptOut($email, $category)
    {
        $optout = new EmailOptOut();
        $optout->setCategory($category);
        $optout->setEmail($email);
        $this->dm->persist($optout);
    }
}
