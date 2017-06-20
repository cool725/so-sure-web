<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Payment;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;

use Doctrine\ODM\MongoDB\DocumentManager;
use Intercom\IntercomClient;

class IntercomService
{
    const KEY_INTERCOM_QUEUE = 'queue:intercom';

    const TAG_DONT_CONTACT = "Don't Contact (Duplicate)";

    const SECURE_WEB = 'web';
    const SECURE_ANDROID = 'android';
    const SECURE_IOS = 'ios';

    const QUEUE_LEAD = 'lead';
    const QUEUE_USER = 'user';

    const QUEUE_EVENT_POLICY_CREATED = 'policy-created';
    const QUEUE_EVENT_POLICY_CANCELLED = 'policy-cancelled';

    const QUEUE_EVENT_PAYMENT_SUCCESS = 'payment-succeed';
    const QUEUE_EVENT_PAYMENT_FAILED = 'payment-failed';
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

    const QUEUE_EVENT_USER_PAYMENT_FAILED = 'userpayment-failed';

    const QUEUE_EVENT_CLAIM_CREATED = 'claim-created';
    const QUEUE_EVENT_CLAIM_APPROVED = 'claim-approved';
    const QUEUE_EVENT_CLAIM_SETTLED = 'claim-settled';

    const QUEUE_EVENT_SAVE_QUOTE = 'quote-saved';

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
    protected $mailer;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $token
     * @param                 $redis
     * @param string          $secure
     * @param string          $secureAndroid
     * @param string          $secureIOS
     * @param                 $mailer
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $token,
        $redis,
        $secure,
        $secureAndroid,
        $secureIOS,
        $mailer
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new IntercomClient($token, null);
        $this->redis = $redis;
        $this->secure = $secure;
        $this->secureAndroid = $secureAndroid;
        $this->secureIOS = $secureIOS;
        $this->mailer = $mailer;
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

    public function getIntercomUser(User $user)
    {
        if (!$user->getIntercomId()) {
            return null;
        }

        try {
            $resp = $this->client->users->getUser($user->getIntercomId());
            $this->logger->info(sprintf('getUser %s %s', $user->getEmail(), json_encode($resp)));

            return $resp;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() == "404") {
                return null;
            }

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
        if ($user->getMobileNumber()) {
            $data['phone'] = $user->getMobileNumber();
        }

        $analytics = $user->getAnalytics();
        $data['custom_attributes']['Premium'] = $analytics['annualPremium'];
        $data['custom_attributes']['Pot'] = $analytics['rewardPot'];
        $data['custom_attributes']['Connections'] = $analytics['connections'];
        $data['custom_attributes']['Approved Claims'] = $analytics['approvedClaims'];
        $data['custom_attributes']['Promo Code'] = $analytics['firstPolicy']['promoCode'];
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

    public function updateLead(Lead $lead, $data = null)
    {
        if (!$data) {
            $data = [];
        }
        $data['email'] = $lead->getEmail();
        if (strlen($lead->getName()) > 0) {
            $data['name'] = $lead->getName();
        }
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
        $data = [];
        if ($event == self::QUEUE_EVENT_POLICY_CANCELLED) {
            $data['metadata']['Cancelled Reason'] = $policy->getCancelledReason();
        }
        $user = $policy->getUser();
        $this->sendEvent($user, $event, $data);
    }

    public function sendPaymentEvent(Payment $payment, $event)
    {
        $user = $payment->getPolicy()->getUser();
        $this->sendEvent($user, $event, []);
    }

    public function sendClaimEvent(Claim $claim, $event)
    {
        $user = $claim->getPolicy()->getUser();
        $this->sendEvent($user, $event, []);
    }

    public function sendSaveQuoteEvent(Lead $lead, $event, $additional)
    {
        $data = [];
        if ($additional && isset($additional['quoteUrl'])) {
            $data['custom_attributes']['Saved Quote Url'] = $additional['quoteUrl'];
        }
        if ($additional && isset($additional['phone'])) {
            $data['custom_attributes']['Saved Quote Phone'] = $additional['phone'];
        }
        if ($additional && isset($additional['price'])) {
            $data['custom_attributes']['Saved Quote Price'] = $additional['price'];
        }
        if ($additional && isset($additional['expires'])) {
            $data['custom_attributes']['Saved Quote Expires'] = $additional['expires']->getTimestamp();
        }
        $this->sendLeadEvent($lead, $event, []);
        // Needs to go on the lead object in order to be able to access property via intercom messaging
        $this->updateLead($lead, $data);
    }

    public function sendInvitationEvent(Invitation $invitation, $event)
    {
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

        $user = null;
        $data = [];
        if ($useInviter && !$this->isDeleted($invitation->getInviter())) {
            $user = $invitation->getInviter();
            $data['metadata']['Invitee Name'] = $invitation->getInvitee()->getName();
        } elseif (!$useInviter && !$this->isDeleted($invitation->getInvitee())) {
            $user = $invitation->getInvitee();
            $data['metadata']['Inviter Name'] = $invitation->getInviter()->getName();
        } else {
            $this->logger->debug(sprintf('Skipping Intercom create event (%s) as user deleted', $event));
            return;
        }

        $this->sendEvent($user, $event, $data);
    }

    private function sendUserPaymentEvent(User $user, $event, $additional)
    {
        $data = [];
        if ($additional && isset($additional['reason'])) {
            $data['metadata']['Reason'] = $additional['reason'];
        }
        $this->sendEvent($user, $event, $data);
    }

    private function sendEvent(User $user, $event, $data, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $data['event_name'] = $event;
        $data['created_at'] = $date->getTimestamp();
        $data['id'] = $user->getIntercomId();
        $data['user_id'] = $user->getId();

        $resp = $this->client->events->create($data);
        $this->logger->debug(sprintf('Intercom create event (%s) %s', $event, json_encode($resp)));
    }

    private function sendLeadEvent(Lead $lead, $event, $data, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $data['event_name'] = $event;
        $data['created_at'] = $date->getTimestamp();
        $data['email'] = $lead->getEmail();
        if ($lead->getIntercomId()) {
            $data['id'] = $lead->getIntercomId();
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
                } elseif ($action == self::QUEUE_LEAD) {
                    if (!isset($data['leadId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->updateLead($this->getLead($data['leadId']));
                } elseif ($action == self::QUEUE_EVENT_SAVE_QUOTE) {
                    if (!isset($data['leadId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendSaveQuoteEvent($this->getLead($data['leadId']), $action, $data['additional']);
                } elseif ($action == self::QUEUE_EVENT_CLAIM_CREATED ||
                          $action == self::QUEUE_EVENT_CLAIM_APPROVED ||
                          $action == self::QUEUE_EVENT_CLAIM_SETTLED) {
                    if (!isset($data['claimId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendClaimEvent($this->getClaim($data['claimId']), $action);
                } elseif ($action == self::QUEUE_EVENT_POLICY_CREATED ||
                          $action == self::QUEUE_EVENT_POLICY_CANCELLED) {
                    if (!isset($data['policyId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendPolicyEvent($this->getPolicy($data['policyId']), $action);
                } elseif ($action == self::QUEUE_EVENT_PAYMENT_SUCCESS ||
                          $action == self::QUEUE_EVENT_PAYMENT_FAILED) {
                    if (!isset($data['paymentId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendPaymentEvent($this->getPayment($data['paymentId']), $action);
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
                } elseif ($action == self::QUEUE_EVENT_USER_PAYMENT_FAILED) {
                    if (!isset($data['userId']) || !isset($data['additional'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendUserPaymentEvent($this->getUser($data['userId']), $action, $data['additional']);
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

    private function getLead($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing leadId');
        }
        $repo = $this->dm->getRepository(Lead::class);
        $lead = $repo->find($id);
        if (!$lead) {
            throw new \InvalidArgumentException(sprintf('Unable to find leadId: %s', $id));
        }

        return $lead;
    }

    private function deleteLead($id)
    {
        try {
            $this->client->leads->deleteLead($id);
            $this->logger->info(sprintf('Deleted intercom lead %s', $id));
        } catch (\Exception $e) {
            $this->logger->info(
                sprintf('Failed to deleted intercom lead %s. Already deleted?', $id),
                ['exception' => $e]
            );
        }
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

    private function deleteUser($id)
    {
        try {
            $this->client->users->deleteUser($id);
            $this->logger->info(sprintf('Deleted intercom user %s', $id));
        } catch (\Exception $e) {
            $this->logger->info(
                sprintf('Failed to deleted intercom lead %s. Already deleted?', $id),
                ['exception' => $e]
            );
        }
    }

    private function getClaim($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing claimId');
        }
        $repo = $this->dm->getRepository(Claim::class);
        $claim = $repo->find($id);
        if (!$claim) {
            throw new \InvalidArgumentException(sprintf('Unable to find claimId: %s', $id));
        }

        return $claim;
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

    private function getPayment($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing paymentId');
        }
        $repo = $this->dm->getRepository(Payment::class);
        $payment = $repo->find($id);
        if (!$payment) {
            throw new \InvalidArgumentException(sprintf('Unable to find paymentId: %s', $id));
        }

        return $payment;
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
        $this->queueUser($user, self::QUEUE_USER, null, $retryAttempts);
    }

    public function queueLead(Lead $lead, $event, $additional = null, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'leadId' => $lead->getId(),
            'retryAttempts' => $retryAttempts,
            'additional' => $additional,
        ];
        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    public function queueUser(User $user, $event, $additional = null, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'userId' => $user->getId(),
            'retryAttempts' => $retryAttempts,
            'additional' => $additional,
        ];
        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    public function queueClaim(Claim $claim, $event, $additional = null, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'claimId' => $claim->getId(),
            'retryAttempts' => $retryAttempts,
            'additional' => $additional,
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

    public function queuePayment(Payment $payment, $event, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'paymentId' => $payment->getId(),
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

    public function maintenance()
    {
        $lines = array_merge($this->leadsMaintenance(), $this->usersMaintenance());
        $this->emailReport($lines);

        return $lines;
    }

    private function emailReport($lines)
    {
        $message = \Swift_Message::newInstance()
            ->setSubject('Intercom Mainteanance and Duplicate Entries')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody(implode(PHP_EOL, $lines), 'text/text');
        $this->mailer->send($message);

    }

    private function leadsMaintenance()
    {
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $output = [];
        $page = 1;
        $pages = 1;
        $now = new \DateTime();
        while ($page <= $pages) {
            $output[] = sprintf('Checking Leads - page %d', $page);
            $resp = $this->client->leads->getLeads(['page' => $page]);
            $page++;
            $pages = $resp->pages->total_pages;
            foreach ($resp->contacts as $lead) {
                if (strlen(trim($lead->email)) > 0) {
                    $optedOut = $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_AQUIRE) ||
                        $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                    if ($lead->unsubscribed_from_emails && !$optedOut) {
                        $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_AQUIRE);
                        $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_RETAIN);
                        $output[] = sprintf("Added optout for %s", $lead->email);
                    }
                }

                if ($lead->last_request_at) {
                    $lastSeen = \DateTime::createFromFormat('U', $lead->last_request_at);
                } elseif ($lead->updated_at) {
                    $lastSeen = \DateTime::createFromFormat('U', $lead->updated_at);
                } elseif ($lead->created_at) {
                    $lastSeen = \DateTime::createFromFormat('U', $lead->created_at);
                } else {
                    $this->logger->warning(sprintf('For lead, unable to determine last seen %s', json_encode($lead)));
                }

                if ($lastSeen) {
                    $age = $now->diff($lastSeen);
                    // Lead w/o email, clear out after 1 week not seen
                    if (!$lead->email && $age->days >= 7) {
                        $output[] = sprintf('Deleting lead %s age: %d', $lead->id, $age->days);
                        $this->deleteLead($lead->id);
                    } elseif ($lead->email && $age->days >= 28) {
                        // Lead w/email, clear out after 4 weeks not seen
                        $output[] = sprintf('Deleting lead %s email: %s age: %d', $lead->id, $lead->email, $age->days);
                        $this->deleteLead($lead->id);
                    }
                }
            }
            $this->dm->flush();
        }

        return $output;
    }

    private function usersMaintenance()
    {
        $userRepo = $this->dm->getRepository(User::class);
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $emails = [];
        $output = [];
        $page = 1;
        $pages = 1;
        $now = new \DateTime();
        while ($page <= $pages) {
            $output[] = sprintf('Checking Users - page %d', $page);
            $resp = $this->client->users->getUsers(['page' => $page]);
            $page++;
            $pages = $resp->pages->total_pages;
            foreach ($resp->users as $user) {
                if (strlen(trim($user->email)) > 0) {
                    $doNotContact = false;
                    foreach ($user->tags->tags as $tag) {
                        $tagName = html_entity_decode($tag->name, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        /*
                        if (strlen($tagName) > 0) {
                            print_r($tagName);
                        }
                        */
                        if (!$doNotContact) {
                            $doNotContact = stripos($tagName, self::TAG_DONT_CONTACT) !== false;
                        }
                    }
                    if (!$doNotContact) {
                        if (isset($emails[trim($user->email)]) && $emails[trim($user->email)] != $user->id) {
                            $output[] = sprintf("Duplicate users for email %s", $user->email);
                        }
                        $emails[trim($user->email)] = $user->id;
                    }
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
                        if ($sosureUser) {
                            $this->updateUser($sosureUser);
                            $output[] = sprintf("Resync intercom user for %s", $user->email);
                        } else {
                            $output[] = sprintf("Unable to find so-sure user for %s", $user->email);
                        }
                    }
                }

                if ($user->last_request_at) {
                    $lastSeen = \DateTime::createFromFormat('U', $user->last_request_at);
                } elseif ($user->updated_at) {
                    $lastSeen = \DateTime::createFromFormat('U', $user->updated_at);
                } elseif ($user->created_at) {
                    $lastSeen = \DateTime::createFromFormat('U', $user->created_at);
                } else {
                    $this->logger->warning(sprintf('For user, unable to determine last seen %s', json_encode($user)));
                }

                if ($lastSeen) {
                    $age = $now->diff($lastSeen);
                    // User w/o email, clear out after 8 weeks not seen
                    if (!$user->email && $age->days >= 56) {
                        $output[] = sprintf('Deleting user %s age: %d', $user->id, $age->days);
                        $this->deleteUser($user->id);
                    }
                    // TODO: User cancelled, archive messages and clear out after 2 weeks not seen
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
