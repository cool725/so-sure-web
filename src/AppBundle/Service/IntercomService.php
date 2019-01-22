<?php
namespace AppBundle\Service;

use AppBundle\Document\DateTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Repository\OptOut\EmailOptOutRepository;
use AppBundle\Repository\UserRepository;
use GuzzleHttp\Exception\ClientException;
use http\Exception;
use Predis\Client;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Connection\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use AppBundle\Document\CurrencyTrait;

use Doctrine\ODM\MongoDB\DocumentManager;
use Intercom\IntercomClient;
use Symfony\Component\Routing\RouterInterface;

class IntercomService
{
    use CurrencyTrait;
    use PhoneTrait;
    use DateTrait;

    const MAX_SCROLL_RECORDS = 50000;

    const KEY_INTERCOM_QUEUE = 'queue:intercom';
    const KEY_INTERCOM_RATELIMIT = 'intercom:ratelimit';

    // slow down requests at this theshold
    const RATE_LIMIT_DELAY = 30;
    // keep some requests free for app
    const RATE_LIMIT_RESERVED_APP = 10;

    const MAX_RATE_LIMIT_SLEEP_SECONDS = 15;


    const TAG_DONT_CONTACT = "Don't Contact (Duplicate)";

    const SECURE_WEB = 'web';
    const SECURE_ANDROID = 'android';
    const SECURE_IOS = 'ios';

    const QUEUE_LEAD = 'lead';
    const QUEUE_USER = 'user';
    const QUEUE_USER_DELETE = 'user-delete';
    const QUEUE_LEAD_DELETE = 'lead-delete';
    const QUEUE_MESSAGE = 'message';

    const QUEUE_EVENT_POLICY_CREATED = 'policy-created';
    const QUEUE_EVENT_POLICY_CANCELLED = 'policy-cancelled';
    const QUEUE_EVENT_POLICY_PENDING_RENEWAL = 'policy-renewal-ready';
    const QUEUE_EVENT_POLICY_RENEWED = 'policy-renewed';
    const QUEUE_EVENT_POLICY_START = 'policy-start';

    const QUEUE_EVENT_PAYMENT_SUCCESS = 'payment-succeed';
    const QUEUE_EVENT_PAYMENT_FAILED = 'payment-failed';
    const QUEUE_EVENT_PAYMENT_FIRST_PROBLEM = 'payment-first-problem';

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
    // same as invitation accepted really, only for both policies
    const QUEUE_EVENT_CONNECTION_CREATED = 'connection-created';

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

    /** @var Client */
    protected $redis;

    protected $secure;
    protected $secureAndroid;
    protected $secureIOS;
    protected $dpaAppAdminId;

    /** @var MailerService */
    protected $mailer;

    /** @var RouterService */
    protected $router;

    /** @var SanctionsService */
    protected $sanctions;

    /** @var RateLimitService */
    protected $rateLimit;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param string           $token
     * @param Client           $redis
     * @param string           $secure
     * @param string           $secureAndroid
     * @param string           $secureIOS
     * @param MailerService    $mailer
     * @param RouterService    $router
     * @param string           $dpaAppAdminId
     * @param SanctionsService $sanctions
     * @param RateLimitService $rateLimitService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $token,
        Client $redis,
        $secure,
        $secureAndroid,
        $secureIOS,
        MailerService $mailer,
        RouterService $router,
        $dpaAppAdminId,
        SanctionsService $sanctions,
        RateLimitService $rateLimitService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new IntercomClient($token, '');
        $this->redis = $redis;
        $this->secure = $secure;
        $this->secureAndroid = $secureAndroid;
        $this->secureIOS = $secureIOS;
        $this->mailer = $mailer;
        $this->router = $router;
        $this->dpaAppAdminId = $dpaAppAdminId;
        $this->sanctions = $sanctions;
        $this->rateLimit = $rateLimitService;
    }

    private function storeRateLimit()
    {
        $rateLimit = $this->client->getRateLimitDetails();
        $this->redis->set(self::KEY_INTERCOM_RATELIMIT, serialize($rateLimit));
        $this->logger->debug(sprintf(
            'Intercom rate limit response: %s',
            json_encode($rateLimit)
        ));
    }

    private function checkRateLimit()
    {
        if (!$this->redis->exists(self::KEY_INTERCOM_RATELIMIT)) {
            return;
        }
        $rateLimit = unserialize($this->redis->get(self::KEY_INTERCOM_RATELIMIT));
        // 83 ops / 10 sec
        if ($rateLimit['remaining'] > self::RATE_LIMIT_DELAY) {
            return;
        } elseif ($rateLimit['remaining'] > self::RATE_LIMIT_RESERVED_APP) {
            $this->logger->debug(sprintf(
                'Sleep 0.1s for intercom rate limit %d remaining',
                $rateLimit['remaining']
            ));
            // wait for 0.1 seconds
            usleep(100000);
        } elseif ($rateLimit['remaining']) {
            $reset = $rateLimit['reset_at'];
            $now = \DateTime::createFromFormat('U', time());
            if ($reset > $now) {
                $diff = $now->diff($reset);
                $this->logger->debug(sprintf(
                    'Sleep %d s until %s (now: %s) for intercom rate limit %d remaining',
                    $diff->s,
                    $reset->format(\DateTime::ATOM),
                    $now->format(\DateTime::ATOM),
                    $rateLimit['remaining']
                ));
                if (!$diff->invert && $diff->s > self::MAX_RATE_LIMIT_SLEEP_SECONDS) {
                    throw new \Exception('Too long to sleep for intercom');
                }
                time_sleep_until($reset->format('U'));
            }
        }
    }

    public function update(User $user, $allowSoSure = false, $undelete = false)
    {
        if ($user->hasSoSureEmail() && !$allowSoSure) {
            return ['skipped' => true];
        }
        if (!$user->hasEmail()) {
            return ['skipped' => true];
        }

        if (!$undelete && $this->isDeleted($user)) {
            return ['deleted' => true];
        }

        $converted = false;
        if (!$this->userExists($user) && $this->leadExists($user)) {
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
        if (!$user->hasEmail()) {
            return false;
        }

        try {
            $this->checkRateLimit();
            // INTERCOM QUERY - DO NOT USE emailCanonical!
            $resp = $this->client->leads->getLeads(['email' => $user->getEmail()]);
            $this->storeRateLimit();

            return count($resp->contacts) > 0;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            throw $e;
        }
    }

    /**
     * @param User $user
     * @return mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getIntercomUser(User $user, $useIntercomId = true)
    {
        if ($useIntercomId && !$user->getIntercomId()) {
            return null;
        }
        if (!$user->hasEmail()) {
            return null;
        }

        try {
            $this->checkRateLimit();
            if ($useIntercomId) {
                // INTERCOM QUERY - DO NOT USE emailCanonical!
                $resp = $this->client->users->getUser($user->getIntercomId());
            } else {
                // INTERCOM QUERY - DO NOT USE emailCanonical!
                $resp = $this->client->users->getUsers(['email' => $user->getEmailCanonical()]);
            }
            $this->storeRateLimit();

            $this->logger->info(sprintf('getUser %s %s', $user->getEmail(), json_encode($resp)));

            return $resp;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() == "404") {
                return null;
            }

            throw $e;
        }
    }

    private function userExists(User $user, $useIntercomId = true)
    {
        if (!$user->getIntercomId()) {
            return false;
        }
        if (!$user->hasEmail()) {
            return false;
        }

        try {
            $this->checkRateLimit();
            if ($useIntercomId) {
                // INTERCOM QUERY - DO NOT USE emailCanonical!
                $resp = $this->client->users->getUser($user->getIntercomId());
            } else {
                // INTERCOM QUERY - DO NOT USE emailCanonical!
                $resp = $this->client->users->getUser($user->getEmail());
            }
            $this->storeRateLimit();

            $this->logger->info(sprintf('getUser %s %s', $user->getEmail(), json_encode($resp)));

            return true;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() == "404") {
                return false;
            }

            throw $e;
        }
    }

    public function convertLead(User $user, $useIntercomId = false)
    {
        $results = [];
        if (!$user->hasEmail()) {
            return $results;
        }
        $this->checkRateLimit();
        if ($useIntercomId) {
            $resp = $this->client->leads->getLead($user->getIntercomUserIdOrId());
        } else {
            // INTERCOM QUERY - DO NOT USE emailCanonical!
            $resp = $this->client->leads->getLeads(['email' => $user->getEmailCanonical()]);
        }
        $this->storeRateLimit();

        $results[] = $resp;
        foreach ($resp->contacts as $lead) {
            if (mb_strtolower($lead->email) != $user->getEmailCanonical()) {
                throw new \Exception(sprintf(
                    'Lead %s/%s does not match user email %s / %s',
                    $lead->email,
                    $lead->id,
                    $user->getEmail(),
                    $user->getIntercomUserIdOrId()
                ));
            }
            $data = [
              "contact" => array("id" => $lead->id),
              "user" => array("user_id" => $user->getIntercomUserIdOrId()),
            ];

            $this->checkRateLimit();
            try {
                $resp = $this->client->leads->convertLead($data);
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                if ($e->getCode() == 404) {
                    $this->logger->debug(sprintf(
                        'Unable to convert Intercom lead (userid %s) %s (404)',
                        $user->getIntercomUserIdOrId(),
                        json_encode($resp)
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'Unable to convert Intercom lead (userid %s) %s (%s)',
                        $user->getIntercomUserIdOrId(),
                        json_encode($resp),
                        $e->getCode()
                    ));

                    throw $e;
                }
            }
            $this->storeRateLimit();

            $this->logger->debug(sprintf(
                'Intercom convert lead (userid %s) %s',
                $user->getIntercomUserIdOrId(),
                json_encode($resp)
            ));
            $results[] = $resp;
        }

        return $results;
    }

    public function resetIntercomUserId(User $user)
    {
        $id = new \MongoId();
        $user->setIntercomUserId($id->serialize());
        $this->dm->flush();
    }

    public function resetIntercomUserIdForLead(Lead $lead)
    {
        $id = new \MongoId();
        $lead->setIntercomUserId($id->serialize());
        $this->dm->flush();
    }

    public function resetIntercomId(User $user)
    {
        $user->setIntercomId(null);
        $this->dm->flush();
    }

    public function resetIntercomIdForLead(Lead $lead)
    {
        $lead->setIntercomId(null);
        $this->dm->flush();
    }

    public function getApiUserHash(User $user = null)
    {
        if (!$user || !$user->getIntercomUserIdOrId()) {
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

        if ($user && $user->getIntercomUserIdOrId()) {
            return hash_hmac('sha256', $user->getIntercomUserIdOrId(), $secure);
        }

        return null;
    }

    private function updateUser(User $user, $isConverted = false)
    {
        if (!$user->hasEmail()) {
            return;
        }

        $data = array(
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'signed_up_at' => $user->getCreated()->getTimestamp(),
            'user_id' => $user->getIntercomUserIdOrId(),
        );
        if ($user->getIntercomId()) {
            $data['id'] = $user->getIntercomId();
        }
        if ($user->getMobileNumber()) {
            $data['phone'] = $user->getMobileNumber();
        }

        $analytics = $user->getAnalytics();
        $data['custom_attributes']['User url'] = $this->router->generateUrl('admin_user', [
            'id' => $user->getId()
        ]);
        $data['custom_attributes']['Premium'] = $this->toTwoDp($analytics['annualPremium']);
        $data['custom_attributes']['Displayable Premium'] = (string) sprintf('%.2f', $analytics['annualPremium']);
        $data['custom_attributes']['Monthly Premium'] = $this->toTwoDp($analytics['monthlyPremium']);
        $data['custom_attributes']['Displayable Monthly Premium'] =
            (string) sprintf('%.2f', $analytics['monthlyPremium']);
        $data['custom_attributes']['Pot'] = $analytics['rewardPot'];
        $data['custom_attributes']['Displayable Pot'] = (string) sprintf('%.2f', $analytics['rewardPot']);
        $data['custom_attributes']['Max Pot'] = $analytics['maxPot'];
        $data['custom_attributes']['Displayable Max Pot'] = (string) sprintf('%.2f', $analytics['maxPot']);
        $data['custom_attributes']['Has Full Pot'] = $analytics['hasFullPot'];
        $data['custom_attributes']['Connections'] = $analytics['connections'];
        $data['custom_attributes']['Approved Claims'] = $analytics['approvedClaims'];
        $data['custom_attributes']['Approved Network Claims'] = $analytics['approvedNetworkClaims'];
        $data['custom_attributes']['Payment Method'] = $analytics['paymentMethod'];
        $data['custom_attributes']['Promo Code'] = $analytics['firstPolicy']['promoCode'];
        $data['custom_attributes']['Pending Invites'] = count($user->getUnprocessedReceivedInvitations());
        $data['custom_attributes']['Number of Policies'] = $analytics['numberPolicies'];
        $data['custom_attributes']['Account Paid To Date'] = $analytics['accountPaidToDate'];
        $data['custom_attributes']['Account Paid To Date'] = $analytics['accountPaidToDate'];
        $data['custom_attributes']['Has Outstanding pic-sure Policy'] = $analytics['hasOutstandingPicSurePolicy'];
        $data['custom_attributes']['Displayable Renewal Monthly Premium'] =
            (string) sprintf('%.2f', $this->toTwoDp($analytics['renewalMonthlyPremiumNoPot']));
        $data['custom_attributes']['Renewal Monthly Premium With Pot'] =
            $this->toTwoDp($analytics['renewalMonthlyPremiumWithPot']);
        $data['custom_attributes']['Displayable Renewal Monthly Premium With Pot'] =
            (string) sprintf('%.2f', $this->toTwoDp($analytics['renewalMonthlyPremiumWithPot']));
        // TODO: Can we remove?
        $data['custom_attributes']['Card Details'] = $user->getPaymentMethod() ?
            $user->getPaymentMethod()->__toString() :
            null;
        $data['custom_attributes']['Policy Cancelled And Payment Owed'] = $user->hasPolicyCancelledAndPaymentOwed();
        if (isset($analytics['devices'])) {
            $data['custom_attributes']['Insured Devices'] = join(';', $analytics['devices']);
        }
        if ($user->getFirstPolicy() && $user->getFirstPolicy()->getPhone()) {
            $data['custom_attributes']['First Policy Learn More'] =
                $this->router->generateUrl('learn_more_phone', [
                    'id' => $user->getFirstPolicy()->getPhone()->getId()
                ]);
        }
        // Only set the first time, or if the user was converted from a lead
        if (!$user->getIntercomId() || $isConverted) {
            if ($user->getIdentityLog() && $user->getIdentityLog()->getIp()) {
                $data['last_seen_ip'] = $user->getIdentityLog()->getIp();
            }
        }

        // optout
        /** @var EmailOptOutRepository $emailOptOutRepo */
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optedOut = $emailOptOutRepo->isOptedOut($user->getEmail(), EmailOptOut::OPTOUT_CAT_MARKETING);
        if ($optedOut) {
            $data['unsubscribed_from_emails'] = true;
        }
        // $encoded = json_encode($data, JSON_PRESERVE_ZERO_FRACTION);
        $this->checkRateLimit();
        $resp = $this->client->users->create($data);
        $this->storeRateLimit();

        $this->logger->debug(sprintf(
            'Intercom create user (userid %s) %s',
            $user->getIntercomUserIdOrId(),
            json_encode($resp, JSON_PRESERVE_ZERO_FRACTION)
        ));

        $user->setIntercomId($resp->id);
        $this->dm->flush();

        return $resp;
    }

    public function updateLead(Lead $lead, $data = null)
    {
        if (!$lead->hasEmail()) {
            return;
        }

        if (!$data) {
            $data = [];
        }
        $data['email'] = $lead->getEmail();
        if (mb_strlen($lead->getName()) > 0) {
            $data['name'] = $lead->getName();
        }
        if (mb_strlen($lead->getSource()) > 0) {
            $data['custom_attributes']['source'] = $lead->getSource();
        }

        if ($lead->getSource() === Lead::LEAD_SOURCE_AFFILIATE) {
            if (mb_strlen($lead->getSourceDetails() > 0)) {
                $data['custom_attributes']['Affiliate'] = $lead->getSourceDetails();
            }
        }
        if ($lead->getIntercomId()) {
            $data['id'] = $lead->getIntercomId();
        }
        $this->checkRateLimit();
        $resp = $this->client->leads->create($data);
        $this->storeRateLimit();

        $this->logger->debug(sprintf(
            'Intercom create lead (userid %s) %s',
            $lead->getIntercomUserIdOrId(),
            json_encode($resp)
        ));

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
        /** @var User $inviter */
        $inviter = $invitation->getInviter();
        /** @var User $invitee */
        $invitee = $invitation->getInvitee();
        if ($inviter && $invitee) {
            if ($useInviter && !$this->isDeleted($inviter)) {
                /** @var User $user */
                $user = $inviter;
                $data['metadata']['Invitee Name'] = $invitee->getName();
            } elseif (!$useInviter && !$this->isDeleted($invitee)) {
                /** @var User $user */
                $user = $invitee;
                $data['metadata']['Inviter Name'] = $inviter->getName();
            } else {
                $this->logger->debug(sprintf('Skipping Intercom create event (%s) as user deleted', $event));
                return;
            }
        }

        if ($user) {
            $this->sendEvent($user, $event, $data);
        }
    }

    private function sendConnectionCreatedEvent(Connection $connection, $event)
    {
        $data = [];
        $data['metadata']['Connected To'] = $connection->getLinkedUser()->getName();
        $this->sendEvent($connection->getSourceUser(), $event, $data);
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
            $date = \DateTime::createFromFormat('U', time());
        }

        $data['event_name'] = $event;
        $data['created_at'] = $date->getTimestamp();
        $data['id'] = $user->getIntercomId();
        $data['user_id'] = $user->getIntercomUserIdOrId();

        $this->checkRateLimit();
        $resp = $this->client->events->create($data);
        $this->storeRateLimit();

        $this->logger->debug(sprintf('Intercom create event (%s) %s', $event, json_encode($resp)));
    }

    private function sendMessage(User $user = null, Lead $lead = null, $data = null, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($user) {
            $data['from']['type'] = 'user';
            $data['from']['id'] = $user->getIntercomId();
            $data['from']['user_id'] = $user->getIntercomUserIdOrId();
        } elseif ($lead) {
            $data['from']['type'] = 'contact';
            $data['from']['id'] = $lead->getIntercomId();
            $data['from']['user_id'] = $lead->getIntercomUserIdOrId();
        }

        $this->checkRateLimit();
        $resp = $this->client->messages->create($data);
        $this->storeRateLimit();

        $this->logger->debug(sprintf('Intercom create event (sendMessage) %s', json_encode($resp)));
    }

    private function sendLeadEvent(Lead $lead, $event, $data, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $data['event_name'] = $event;
        $data['created_at'] = $date->getTimestamp();
        $data['email'] = $lead->getEmail();
        if ($lead->getIntercomId()) {
            $data['id'] = $lead->getIntercomId();
        }

        $this->checkRateLimit();
        $resp = $this->client->events->create($data);
        $this->storeRateLimit();

        $this->logger->debug(sprintf('Intercom create event (%s) %s', $event, json_encode($resp)));
    }

    public function process($max)
    {
        $requeued = 0;
        $processed = 0;
        while ($processed + $requeued < $max) {
            $user = null;
            $data = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_INTERCOM_QUEUE);
                if (!$queueItem) {
                    return $processed;
                }
                $data = unserialize($queueItem);

                if (isset($data['action'])) {
                    $action = $data['action'];
                } else {
                    // legacy before action was used.  can be removed soon after
                    $action = self::QUEUE_USER;
                }

                // Requeue anything not yet ready to process
                $now = \DateTime::createFromFormat('U', time());
                if (isset($data['processTime'])
                    && $data['processTime'] > $now->format('U')) {
                    $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
                    $requeued++;
                    continue;
                }

                if ($action == self::QUEUE_USER) {
                    if (!isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->update($this->getUser($data['userId']));
                } elseif ($action == self::QUEUE_USER_DELETE) {
                    if (!isset($data['additional']) || !isset($data['additional']['intercomId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->deleteUser($data['additional']['intercomId'], true);
                } elseif ($action == self::QUEUE_LEAD_DELETE) {
                    if (!isset($data['additional']) || !isset($data['additional']['intercomId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->deleteLead($data['additional']['intercomId']);
                } elseif ($action == self::QUEUE_LEAD) {
                    if (!isset($data['leadId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->updateLead($this->getLead($data['leadId']));
                } elseif ($action == self::QUEUE_MESSAGE) {
                    if (!isset($data['leadId']) && !isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $user = null;
                    $lead = null;
                    if (isset($data['userId'])) {
                        $user = $this->getUser($data['userId']);
                    }
                    if (isset($data['leadId'])) {
                        $lead = $this->getLead($data['leadId']);
                    }

                    $this->sendMessage($user, $lead, $data);
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
                } elseif (in_array($action, [
                    self::QUEUE_EVENT_POLICY_CREATED,
                    self::QUEUE_EVENT_POLICY_CANCELLED,
                    self::QUEUE_EVENT_POLICY_PENDING_RENEWAL,
                    self::QUEUE_EVENT_POLICY_RENEWED,
                    self::QUEUE_EVENT_POLICY_START,
                ])) {
                    if (!isset($data['policyId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendPolicyEvent($this->getPolicy($data['policyId']), $action);
                } elseif (in_array($action, [
                    self::QUEUE_EVENT_PAYMENT_SUCCESS,
                    self::QUEUE_EVENT_PAYMENT_FAILED,
                    self::QUEUE_EVENT_PAYMENT_FIRST_PROBLEM,
                ])) {
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
                } elseif ($action == self::QUEUE_EVENT_CONNECTION_CREATED) {
                    if (!isset($data['connectionId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->sendConnectionCreatedEvent($this->getConnection($data['connectionId']), $action);
                } else {
                    throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $processed++;
            } catch (\InvalidArgumentException $e) {
                $this->logger->error(sprintf(
                    'Error processing Intercom queue message %s. Ex: %s',
                    json_encode($data),
                    $e->getMessage()
                ));
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    $this->requeue($data, $e);
                } else {
                    $this->logger->info(sprintf(
                        'Error sending message (unknown user) to Intercom %s. Ex: %s',
                        json_encode($data),
                        $e->getMessage()
                    ));
                }
            } catch (\Exception $e) {
                $this->requeue($data, $e);
            }
        }

        return $processed;
    }

    private function requeue($data, \Exception $e)
    {
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
            $this->checkRateLimit();
            $this->client->leads->deleteLead($id);
            $this->storeRateLimit();

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

    private function findUser($email)
    {
        $repo = $this->dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $user;
    }

    private function findLead($email)
    {
        $repo = $this->dm->getRepository(Lead::class);
        $lead = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $lead;
    }

    private function deleteUser($id, $permanent = false)
    {
        try {
            $this->checkRateLimit();
            if ($permanent) {
                $this->client->users->permanentlyDeleteUser($id);
            } else {
                $this->client->users->deleteUser($id);
            }
            $this->storeRateLimit();

            $this->logger->info(sprintf('Deleted intercom user %s', $id));
        } catch (\Exception $e) {
            $this->logger->info(
                sprintf('Failed to deleted intercom user %s. Already deleted?', $id),
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

    private function getConnection($id)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Missing invitationId');
        }
        $repo = $this->dm->getRepository(Connection::class);
        $connection = $repo->find($id);
        if (!$connection) {
            throw new \InvalidArgumentException(sprintf('Unable to find connectionId: %s', $id));
        }

        return $connection;
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

    public function queueConnection(Connection $connection, $retryAttempts = 0)
    {
        $data = [
            'action' => self::QUEUE_EVENT_CONNECTION_CREATED,
            'connectionId' => $connection->getId(),
            'retryAttempts' => $retryAttempts
        ];
        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    public function clearQueue()
    {
        $this->redis->del([self::KEY_INTERCOM_QUEUE]);
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

    public function queueMessage($email, $message, $source = null, $retryAttempts = 0)
    {
        $data = [
            'action' => self::QUEUE_MESSAGE,
            'retryAttempts' => $retryAttempts,
            'body' => $message,
        ];
        $foundUserOrLead = false;
        if ($user = $this->findUser($email)) {
            $data['userId'] = $user->getId();
            $foundUserOrLead = true;
        }
        if ($lead = $this->findLead($email)) {
            $data['leadId'] = $lead->getId();
            $foundUserOrLead = true;
        }

        if (!$foundUserOrLead) {
            $lead = new Lead();
            $lead->setEmail(mb_strtolower($email));
            if ($source) {
                $lead->setSource($source);
            }
            $this->dm->persist($lead);
            $this->dm->flush();

            $this->queueLead($lead, self::QUEUE_LEAD);

            $now = \DateTime::createFromFormat('U', time());
            $now = $now->add(new \DateInterval('PT5M'));
            $data['processTime'] = $now->format('U');
            $data['leadId'] = $lead->getId();
        }

        $this->redis->rpush(self::KEY_INTERCOM_QUEUE, serialize($data));
    }

    private function emailReport($lines)
    {
        $this->mailer->send(
            'Intercom Mainteanance and Duplicate Entries',
            'tech+ops@so-sure.com',
            null,
            implode(PHP_EOL, $lines)
        );
    }

    public function countQueue()
    {
        return $this->redis->llen(self::KEY_INTERCOM_QUEUE);
    }

    public function leadsMaintenance()
    {
        /** @var EmailOptOutRepository $emailOptOutRepo */
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $output = [];
        $count = 0;
        $scroll = null;
        $now = \DateTime::createFromFormat('U', time());
        while ($count < self::MAX_SCROLL_RECORDS) {
            $output[] = sprintf('Checking Leads - Scroll: %s / Count: %d', $scroll, $count);
            //print sprintf('Checking Leads - %s', $scroll) . PHP_EOL;
            $options = [];
            if ($scroll) {
                $options['scroll_param'] = $scroll;
            }
            $this->checkRateLimit();
            try {
                $resp = $this->client->leads->scrollLeads($options);
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    $resp = new \stdClass();
                    $resp->scroll_param = $scroll;
                    $resp->contacts = [];
                } else {
                    throw $e;
                }
            }
            $this->storeRateLimit();

            $scroll = $resp->scroll_param;
            if (count($resp->contacts) == 0) {
                break;
            }

            foreach ($resp->contacts as $lead) {
                if (mb_strlen(trim($lead->email)) > 0) {
                    $optedOut = $emailOptOutRepo->isOptedOut($lead->email, EmailOptOut::OPTOUT_CAT_MARKETING);
                    if ($lead->unsubscribed_from_emails && !$optedOut) {
                        $this->addEmailOptOut($lead->email, EmailOptOut::OPTOUT_CAT_MARKETING);
                        $output[] = sprintf("Added optout for %s", $lead->email);
                    }
                }

                $lastSeen = null;
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
                $count++;
            }
            $this->dm->flush();
        }
        $output[] = sprintf('Total Leads Checked: %d', $count);

        return $output;
    }

    public function destroy(User $user)
    {
        $intercomId = $user->getIntercomId();
        $this->resetIntercomUserId($user);
        $this->resetIntercomId($user);
        $this->deleteUser($intercomId, true);
    }

    public function destroyLead(Lead $lead)
    {
        $intercomId = $lead->getIntercomId();
        $this->resetIntercomUserIdForLead($lead);
        $this->resetIntercomIdForLead($lead);
        $this->deleteLead($intercomId);
    }

    public function usersMaintenance()
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        /** @var EmailOptOutRepository $emailOptOutRepo */
        $emailOptOutRepo = $this->dm->getRepository(EmailOptOut::class);

        $emails = [];
        $output = [];
        $count = 0;
        $scroll = null;
        $now = \DateTime::createFromFormat('U', time());
        while ($count < self::MAX_SCROLL_RECORDS) {
            $output[] = sprintf('Checking Users - Scroll: %s / Count: %d', $scroll, $count);
            // print sprintf('Checking Users - %s', $scroll) . PHP_EOL;
            $options = [];
            if ($scroll) {
                $options['scroll_param'] = $scroll;
            }
            $this->checkRateLimit();
            try {
                $resp = $this->client->users->scrollUsers($options);
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    $resp = new \stdClass();
                    $resp->scroll_param = $scroll;
                    $resp->users = [];
                } else {
                    throw $e;
                }
            }
            $this->storeRateLimit();

            $scroll = $resp->scroll_param;
            if (count($resp->users) == 0) {
                break;
            }

            foreach ($resp->users as $user) {
                if (mb_strlen(trim($user->email)) > 0) {
                    $doNotContact = false;
                    foreach ($user->tags->tags as $tag) {
                        $tagName = html_entity_decode($tag->name, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        /*
                        if (strlen($tagName) > 0) {
                            print_r($tagName);
                        }
                        */
                        if (!$doNotContact) {
                            $doNotContact = mb_stripos($tagName, self::TAG_DONT_CONTACT) !== false;
                        }
                    }
                    if (!$doNotContact) {
                        if (isset($emails[trim($user->email)]) && $emails[trim($user->email)] != $user->id) {
                            $output[] = sprintf("Duplicate users for email %s", $user->email);
                        }
                        $emails[trim($user->email)] = $user->id;
                    }
                    $optedOut = $emailOptOutRepo->isOptedOut($user->email, EmailOptOut::OPTOUT_CAT_MARKETING);
                    if ($user->unsubscribed_from_emails && !$optedOut) {
                        // Webhook callback from intercom issue
                        $this->addEmailOptOut($user->email, EmailOptOut::OPTOUT_CAT_MARKETING);
                        $output[] = sprintf("Added optout for %s", $user->email);
                    } elseif (!$user->unsubscribed_from_emails && $optedOut) {
                        // sosure user listener -> queue -> intercom update issue
                        /** @var User $sosureUser */
                        $sosureUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($user->email)]);
                        if ($sosureUser) {
                            $this->updateUser($sosureUser);
                            $output[] = sprintf("Resync intercom user for %s", $user->email);
                        } else {
                            $output[] = sprintf("Unable to find so-sure user for %s", $user->email);
                        }
                    }
                }

                $lastSeen = null;
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
                $count++;
            }
            $this->dm->flush();
        }
        $output[] = sprintf('Total Users Checked: %d', $count);

        return $output;
    }

    private function addEmailOptOut($email, $category)
    {
        $optout = new EmailOptOut();
        $optout->setLocation(EmailOptOut::OPT_LOCATION_INTERCOM);
        $optout->addCategory($category);
        $optout->setEmail($email);
        $this->dm->persist($optout);
    }

    public function getDpaCard($firstName = null, $lastName = null, $dob = null, $mobile = null, $error = null)
    {
        // for init, all values will be null
        $init = false;
        $headline = null;
        $secondaryHeadline = null;
        $headlineError = false;
        if ($firstName === null && $lastName === null && $dob === null && $mobile === null) {
            $init = true;
            // @codingStandardsIgnoreStart
            $headline = 'For your security, we just need to confirm a few details before we can access your policy.';
            $secondaryHeadline = 'Please check your name exactly matches your policy document.';
            // @codingStandardsIgnoreEnd
        } elseif (!$this->isValidName($firstName) || !$this->isValidName($lastName) ||
            !$this->isValidDOB($dob) || !$this->isValidMobile($mobile)) {
            $headlineError = true;
            // @codingStandardsIgnoreStart
            $headline = 'Oh no! Something is wrong with the information you provided… ';
            $secondaryHeadline = 'Please review your answers and check they are in the correct format and exactly match your policy document.';
            // @codingStandardsIgnoreEnd
        } elseif ($error) {
            $headlineError = true;
            $headline = $error;
            // @codingStandardsIgnoreStart
            $secondaryHeadline = 'Please review your answers and check they are in the correct format and exactly match your policy document.';
            // @codingStandardsIgnoreEnd
        }

        $response = [];
        if ($headline) {
            $response['canvas']['content']['components'][] = [
                'type' => 'text',
                'text' => $headline,
                'style' => $headlineError ? 'error' : 'paragraph',
            ];
        }

        if ($secondaryHeadline) {
            $response['canvas']['content']['components'][] = [
                'type' => 'text',
                'text' => $secondaryHeadline,
                'style' => $headlineError ? 'error' : 'paragraph',
            ];
        }

        if ($headline || $secondaryHeadline) {
            $response['canvas']['content']['components'][] = [
                'type' => 'spacer',
                'size' => 's',
            ];
        }

        $response['canvas']['content']['components'][] = [
            'type' => 'input',
            'id' => 'firstName',
            'label' => 'First Name',
            'value' => $firstName,
            'save_state' => $init || (!$error && $this->isValidName($firstName)) ? 'unsaved' : 'failed',
        ];
        $response['canvas']['content']['components'][] = [
            'type' => 'input',
            'id' => 'lastName',
            'label' => 'Last Name',
            'value' => $lastName,
            'save_state' => $init || (!$error && $this->isValidName($lastName)) ? 'unsaved' : 'failed',
        ];
        $response['canvas']['content']['components'][] = [
            'type' => 'input',
            'id' => 'dob',
            'label' => 'Date of Birth (dd/mm/yyyy)',
            'value' => $dob,
            'save_state' => $init || (!$error && $this->isValidDOB($dob)) ? 'unsaved' : 'failed',
        ];
        $response['canvas']['content']['components'][] = [
            'type' => 'input',
            'id' => 'mobile',
            'label' => 'Mobile Number',
            'value' => $mobile,
            'save_state' => $init || (!$error && $this->isValidMobile($mobile)) ? 'unsaved' : 'failed',
        ];
        $response['canvas']['content']['components'][] = [
            'type' => 'button',
            'id' => 'verify',
            'style' => 'primary',
            'label' => 'Verify me',
            'action' => ['type' => 'submit'],
        ];

        if ($error) {
            $response['canvas']['content']['components'][] = [
                'type' => 'button',
                'id' => 'manual',
                'style' => 'link',
                'label' => 'My details above are correct, but verify me does not work',
                'action' => ['type' => 'submit'],
            ];
        }

        return $response;
    }

    private function getUserByMobile($mobile)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['mobileNumber' => $this->normalizeUkMobile($mobile)]);

        return $user;
    }

    /**
     * @param string $firstName
     * @param string $lastName
     * @param string $dob
     * @param string $mobile
     * @return User|array TODO: Would be nicer to throw an exception with the array
     * @throws \Exception
     */
    public function getValidatedDpaUser($firstName, $lastName, $dob, $mobile)
    {
        if (!$this->isValidName($firstName) || !$this->isValidName($lastName) ||
            !$this->isValidDOB($dob) || !$this->isValidMobile($mobile)) {
            return $this->getDpaCard($firstName, $lastName, $dob, $mobile);
        }

        $user = $this->getUserByMobile($mobile);
        if (!$user) {
            return $this->getDpaCard(
                $firstName,
                $lastName,
                $dob,
                $mobile,
                'Unfortunately, we are unable to locate your details in our system…'
            );
        }

        // To avoid minor issues with typos or misspellings, if name is very close, the accept it
        if ($this->sanctions->getMinLevenshteinDoupleMetaphoneString($firstName, $user->getFirstName()) == 0) {
            $firstName = $user->getFirstName();
        }

        // To avoid minor issues with typos or misspellings, if name is very close, the accept it
        if ($this->sanctions->getMinLevenshteinDoupleMetaphoneString($lastName, $user->getLastName()) == 0) {
            $lastName = $user->getLastName();
        }

        $validate = $user->validateDpa($firstName, $lastName, $dob, $mobile);

        if ($validate == User::DPA_VALIDATION_NOT_VALID) {
            return $this->getDpaCard($firstName, $lastName, $dob, $mobile);
        } elseif (in_array($validate, [
            User::DPA_VALIDATION_FAIL_MOBILE,
            User::DPA_VALIDATION_FAIL_FIRSTNAME,
            User::DPA_VALIDATION_FAIL_LASTNAME,
            User::DPA_VALIDATION_FAIL_DOB,
        ])) {
            return $this->getDpaCard(
                $firstName,
                $lastName,
                $dob,
                $mobile,
                'Unfortunately, we were unable to validate your details.'
            );
        }

        // failsafe in case we add a new case and missed above
        if ($validate != User::DPA_VALIDATION_VALID) {
            throw new \Exception(sprintf('Validate returned %s, not expected validation', $validate));
        }

        return $user;
    }

    public function getAdminIdForConversationId($conversationId)
    {
        $conversation = $this->client->conversations->getConversation($conversationId);

        return $this->getAdminIdForConversation($conversation);
    }

    public function getAdminIdForConversation($conversation)
    {
        $adminId = $conversation->assignee->id;
        $admin = $this->client->admins->getAdmin($adminId);
        if ($admin->type != 'admin') {
            $adminId = null;
        }

        if (!$adminId) {
            $adminId = $this->dpaAppAdminId;
        }

        return $adminId;
    }

    public function getUserIdForConversationId($conversationId)
    {
        $conversation = $this->client->conversations->getConversation($conversationId);

        return $this->getUserIdForConversation($conversation);
    }

    public function getUserIdForConversation($conversation)
    {
        return $conversation->user->id;
    }

    private function getSearchUserUrl($firstName, $lastName, $dob, $mobile)
    {
        $searchUrl = $this->router->generateUrl('admin_users', [
            'user_search[firstname]' => $firstName,
            'user_search[lastname]' => $lastName,
            'user_search[mobile]' => $mobile,
            'user_search[dob]' => $dob,
        ]);

        return $searchUrl;
    }

    public function sendSearchUserNote($firstName, $lastName, $dob, $mobile, $conversationId, $prefix, $adminId = null)
    {
        if (!$adminId) {
            $adminId = $this->getAdminIdForConversationId($conversationId);
        }

        $searchUrl = $this->getSearchUserUrl($firstName, $lastName, $dob, $mobile);

        $this->addNote(
            $conversationId,
            sprintf('%s <a href="%s">Search using provided details</a>', $prefix, $searchUrl),
            $adminId
        );
    }

    public function validateDpa($firstName, $lastName, $dob, $mobile, $conversationId)
    {
        $conversation = $this->client->conversations->getConversation($conversationId);
        $adminId = $this->getAdminIdForConversation($conversation);
        $userId = $this->getUserIdForConversation($conversation);

        if (!$this->rateLimit->allowedByUserId($userId, RateLimitService::DEVICE_TYPE_INTERCOM_DPA)) {
            $this->sendReply(
                $conversationId,
                'Sorry, we were unable to validate your DPA, but one of the team will get back to you soon.',
                $adminId
            );

            $this->sendSearchUserNote(
                $firstName,
                $lastName,
                $dob,
                $mobile,
                $conversationId,
                'Too many search attempts.',
                $adminId
            );

            return $this->canvasText(
                'DPA Completed (unsuccessfully)'
            );
        }

        $user = $this->getValidatedDpaUser($firstName, $lastName, $dob, $mobile);
        if (!$user instanceof User) {
            // user was not validated; should be a card with returned as user
            return $user;
        }

        $userLink = $this->router->generateUrl('admin_user', ['id' => $user->getId()]);

        $this->addNote(
            $conversationId,
            sprintf('DPA successfully confirmed for user <a href="%s">%s</a>', $userLink, $user->getName()),
            $adminId
        );

        // @codingStandardsIgnoreStart
        $this->sendReply(
            $conversationId,
            'Thanks for confirming your identity. We will look into your request and soon as possible and respond via this chat or email.',
            $adminId
        );
        // @codingStandardsIgnoreEnd

        return $this->canvasText('DPA Completed');
    }

    public function sendReply($conversationId, $text, $adminId = null)
    {
        if (!$adminId) {
            $adminId = $this->getAdminIdForConversationId($conversationId);
        }

        $this->client->conversations->replyToConversation($conversationId, [
            'type' => 'admin',
            'message_type' => 'comment',
            'admin_id' => $adminId,
            'body' => $text,
        ]);
    }

    public function addNote($conversationId, $text, $adminId = null)
    {
        if (!$adminId) {
            $adminId = $this->getAdminIdForConversationId($conversationId);
        }

        $this->client->conversations->replyToConversation($conversationId, [
            'type' => 'admin',
            'message_type' => 'note',
            'admin_id' => $adminId,
            'body' => $text,
        ]);
    }

    public function canvasText($text, $align = 'left')
    {
        return [
            'canvas' => [
                'content' => [
                    'components' => [
                        [
                            'type' => 'text',
                            'text' => $text,
                            'align' => $align,
                        ],
                    ]
                ]
            ]
        ];
    }

    private function isValidName($name)
    {
        return mb_strlen(trim($name)) > 0;
    }

    private function isValidDOB($dob)
    {
        return $this->isValidDate($dob);
    }

    private function isValidMobile($mobile)
    {
        return $this->isValidUkMobile($mobile);
    }
}
