<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\User;
use Mixpanel;
use UAParser\Parser;

class MixpanelService
{
    const KEY_MIXPANEL_QUEUE = 'queue:mixpanel';

    const QUEUE_PERSON_PROPERTIES = 'person';
    const QUEUE_PERSON_PROPERTIES_ONCE = 'person-once';
    const QUEUE_PERSON_INCREMENT = 'person-increment';
    const QUEUE_TRACK = 'track';
    const QUEUE_ALIAS = 'alias';

    const EVENT_HOME_PAGE = 'Home Page';
    const EVENT_QUOTE_PAGE = 'Quote Page';
    const EVENT_RECEIVE_DETAILS = 'Receive Personal Details';
    const EVENT_PURCHASE_POLICY = 'Purchase Policy';
    const EVENT_PAYMENT = 'Payment';
    const EVENT_INVITE = 'Invite someone';
    const EVENT_CONNECTION_COMPLETE = 'Connection Complete';
    const EVENT_BUY_BUTTON_CLICKED = 'Click on the Buy Now Button';
    const EVENT_POLICY_READY = 'Policy Ready For Purchase';
    const EVENT_LOGIN = 'Login';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $redis;

    /** @var Mixpanel */
    protected $mixpanel;

    /** @var RequestService */
    protected $requestService;

    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $redis
     * @param Mixpanel        $mixpanel
     * @param RequestService  $requestService
     * @param                 $environment
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $redis,
        Mixpanel $mixpanel,
        RequestService $requestService,
        $environment
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->mixpanel = $mixpanel;
        $this->requestService = $requestService;
        $this->environment = $environment;
    }

    private function canSend()
    {
        if ($this->environment == 'test') {
            return false;
        }

        if ($userAgent = $this->requestService->getUserAgent()) {
            if (!$this->isUserAgentAllowed($userAgent)) {
                return false;
            }
        }

        if ($this->requestService->isSoSureEmployee()) {
            return false;
        }

        return true;
    }

    public function clearQueue()
    {
        $this->redis->del(self::KEY_MIXPANEL_QUEUE);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_MIXPANEL_QUEUE, 0, $max);
    }

    public function process($max)
    {
        $count = 0;
        while ($count < $max) {
            $user = null;
            $data = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_MIXPANEL_QUEUE);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (isset($data['action'])) {
                    $action = $data['action'];
                }

                if ($action == self::QUEUE_PERSON_PROPERTIES) {
                    if (!isset($data['userId']) || !isset($data['properties'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $ip = isset($data['ip']) ? $data['ip'] : null;

                    $this->setPersonProperties($data['userId'], $data['properties'], $ip);
                } elseif ($action == self::QUEUE_PERSON_PROPERTIES_ONCE) {
                    if (!isset($data['userId']) || !isset($data['properties'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $ip = isset($data['ip']) ? $data['ip'] : null;

                    $this->setPersonPropertiesOnce($data['userId'], $data['properties'], $ip);
                } elseif ($action == self::QUEUE_PERSON_INCREMENT) {
                    if (!isset($data['userId']) || !isset($data['properties']) ||
                        !isset($data['properties']['field']) || !isset($data['properties']['incrementBy'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->incrementPerson(
                        $data['userId'],
                        $data['properties']['field'],
                        $data['properties']['incrementBy']
                    );
                } elseif ($action == self::QUEUE_TRACK) {
                    if (!isset($data['userId']) || !isset($data['event']) || !isset($data['properties'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->track($data['userId'], $data['event'], $data['properties']);
                } elseif ($action == self::QUEUE_ALIAS) {
                    if (!isset($data['userId']) || !isset($data['properties']) ||
                        !isset($data['properties']['trackingId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->alias($data['properties']['trackingId'], $data['userId']);
                } else {
                    throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $count = $count + 1;
            } catch (\InvalidArgumentException $e) {
                $this->logger->error(sprintf(
                    'Error processing Mixpanel queue message %s. Ex: %s',
                    json_encode($data),
                    $e->getMessage()
                ));
            } catch (\Exception $e) {
                if (isset($data['retryAttempts']) && $data['retryAttempts'] < 2) {
                    $data['retryAttempts'] += 1;
                    $this->redis->rpush(self::KEY_MIXPANEL_QUEUE, serialize($data));
                } else {
                    $this->logger->error(sprintf(
                        'Error (retry exceeded) sending message to Mixpanel %s. Ex: %s',
                        json_encode($data),
                        $e->getMessage()
                    ));
                }
            }
        }

        return $count;
    }

    public function queue($action, $userId, $properties, $event = null, $retryAttempts = 0)
    {
        if (!$this->canSend() || !$userId) {
            return;
        }

        if ($action == self::QUEUE_TRACK) {
            $now = new \DateTime();
            $properties = array_merge($properties, ['time' => $now->getTimestamp()]);
        }

        $data = [
            'action' => $action,
            'userId' => $userId,
            'event' => $event,
            'retryAttempts' => $retryAttempts,
            'properties' => $properties,
            'ip' => $this->requestService->getClientIp(),
        ];
        $this->redis->rpush(self::KEY_MIXPANEL_QUEUE, serialize($data));
    }

    public function queuePersonIncrement($field, $incrementBy = 1, $user = null)
    {
        $userId = null;
        if (!$user) {
            $user = $this->requestService->getUser();
        }
        if ($user) {
            $userId = $user->getId();
        } else {
            $userId = $this->requestService->getTrackingId();
        }

        $this->queue(self::QUEUE_PERSON_INCREMENT, $userId, [
            'field' => $field,
            'incrementBy' => $incrementBy
        ]);
    }

    public function queuePersonProperties(array $personProperties, $setOnce = false, $user = null)
    {
        // don't send empty data
        if (count($personProperties) == 0) {
            return;
        }

        $userId = null;
        if (!$user) {
            $user = $this->requestService->getUser();
        }
        if ($user) {
            $userId = $user->getId();
        } else {
            $userId = $this->requestService->getTrackingId();
        }

        if ($setOnce) {
            $this->queue(self::QUEUE_PERSON_PROPERTIES_ONCE, $userId, $personProperties);
        } else {
            $this->queue(self::QUEUE_PERSON_PROPERTIES, $userId, $personProperties);
        }
    }

    private function incrementPerson($userId, $field, $incrementBy)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        // null ip, ignore time
        $this->mixpanel->people->increment($userId, $field, $incrementBy, null, true);
    }

    private function setPersonProperties($userId, array $personProperties, $ip = null)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        $this->mixpanel->people->set($userId, $personProperties, $ip);
    }

    private function setPersonPropertiesOnce($userId, array $personProperties, $ip = null)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        $this->mixpanel->people->setOnce($userId, $personProperties, $ip);
    }

    private function track($userId, $event, $properties)
    {
        if (!$this->canSend()) {
            return;
        }

        $this->mixpanel->identify($userId);
        $this->mixpanel->track($event, $properties);
    }

    private function alias($trackingId, $userId)
    {
        if (!$this->canSend()) {
            return;
        }

        $this->mixpanel->createAlias($trackingId, $userId);
    }

    public function updateUser(User $user)
    {
        $userData = ['$email' => $user->getEmail()];
        if ($user->getFirstName()) {
            $userData['$first_name'] = $user->getFirstName();
        }
        if ($user->getLastName()) {
            $userData['$last_name'] = $user->getLastName();
        }
        if ($user->getMobileNumber()) {
            $userData['$phone'] = $user->getMobileNumber();
        }
        if ($user->getBirthday()) {
            $userData['Date of Birth'] = $user->getBirthday()->format(\DateTime::ATOM);
        }
        if ($user->getBillingAddress()) {
            $userData['Billing Address'] = $user->getBillingAddress()->__toString();
        }
        if ($policy = $user->getCurrentPolicy()) {
            if ($phone = $policy->getPhone()) {
                $userData['Device Insured'] = $phone->__toString();
                $userData['OS'] = $phone->getOs();
            }
            if ($premium = $policy->getPremium()) {
                $userData['Final Monthly Cost'] = $premium->getMonthlyPremiumPrice();
            }
            if ($plan = $policy->getPremiumPlan()) {
                $userData['Payment Option'] = $plan;
                $userData['Number of Payments Received'] = count($policy->getSuccessfulPaymentCredits());
                if ($payment = $policy->getLastSuccessfulPaymentCredit()) {
                    $userData['Last payment received'] = $payment->getDate()->format(\DateTime::ATOM);
                }
            }
            $userData['Number of Connections'] = count($policy->getConnections());
            $userData['Reward Pot Value'] = $policy->getPotValue();
            if ($connection = $policy->getLastConnection()) {
                $userData['Last connection complete'] = $connection->getDate()->format(\DateTime::ATOM);
            }
        }

        $this->queuePersonProperties($userData, false, $user);
    }

    public function queueTrack($event, array $properties = null)
    {
        return $this->queueTrackAll($event, $properties);
    }

    public function queueTrackWithUtm($event, array $properties = null)
    {
        return $this->queueTrackAll($event, $properties, null, true);
    }

    public function isUserAgentAllowed($userAgent)
    {
        $parser = Parser::create();
        $userAgentDetails = $parser->parse($userAgent);

        // exclude bots from tracking
        if (in_array($userAgentDetails->ua->family, [
            'PhantomJS',
            'SeznamBot',
            'Googlebot',
            'Sogou web spider',
            'Baiduspider',
            'Yahoo! Slurp'
        ])) {
            return false;
        }

        if (stripos($userAgent, 'StatusCake') !== false) {
            return false;
        }

        return true;
    }

    public function queueTrackWithUser($user, $event, array $properties = null)
    {
        return $this->queueTrackAll($event, $properties, $user);
    }

    public function queueTrackAll(
        $event,
        array $properties = null,
        $user = null,
        $addUtm = false
    ) {
        $userId = null;
        if (!$user) {
            $user = $this->requestService->getUser();
        }
        if ($user) {
            $this->updateUser($user);
            $userId = $user->getId();
        } else {
            $userId = $this->requestService->getTrackingId();
        }

        if (!$properties) {
            $properties = [];
        }
        if ($addUtm) {
            $utm = $this->transformUtm();
            $this->queuePersonProperties($utm, true, $user);
            $properties = array_merge($properties, $utm);
        }

        if ($uri = $this->requestService->getUri()) {
            $properties['URL'] = $uri;
        }
        if ($ip = $this->requestService->getClientIp()) {
            $properties['ip'] = $ip;
        }

        if ($userAgent = $this->requestService->getUserAgent()) {
            $parser = Parser::create();
            $userAgentDetails = $parser->parse($userAgent);
            $properties['$browser'] = $userAgentDetails->ua->family;
            $properties['$browser_version'] = $userAgentDetails->ua->toVersion();
            $properties['User Agent'] = $userAgent;
        }
        $this->queue(self::QUEUE_TRACK, $userId, $properties, $event);

        // Special case for logins - bump login count
        if ($event == self::EVENT_LOGIN) {
            $this->queuePersonIncrement("Number Of Logins", 1);
        }
    }

    public function register(User $user = null, $trackingId = null)
    {
        if (!$trackingId) {
            $trackingId = $this->requestService->getTrackingId();
        }
        if ($user && $trackingId) {
            $this->logger->debug(sprintf(
                'Alias user %s to tracking id: %s',
                $user ? $user->getId() : 'unknown',
                $trackingId
            ));
            $this->queue(self::QUEUE_ALIAS, $user->getId(), ['trackingId' => $trackingId]);
        } else {
            $this->logger->warning(sprintf(
                'Failed to register user %s id: %s',
                $user ? $user->getId() : 'unknown',
                $trackingId
            ));
        }
    }

    public function delete($id)
    {
        $this->mixpanel->people->deleteUser($id);
    }

    private function transformUtm()
    {
        $utm = $this->requestService->getUtm();
        if (!$utm) {
            return [];
        }

        $transform = [];
        if (isset($utm['source']) && $utm['source']) {
            $transform['Campaign Source'] = $utm['source'];
        }
        if (isset($utm['medium']) && $utm['medium']) {
            $transform['Campaign Medium'] = $utm['medium'];
        }
        if (isset($utm['campaign']) && $utm['campaign']) {
            $transform['Campaign Name'] = $utm['campaign'];
        }
        if (isset($utm['term']) && $utm['term']) {
            $transform['Campaign Term'] = $utm['term'];
        }
        if (isset($utm['content']) && $utm['content']) {
            $transform['Campaign Content'] = $utm['content'];
        }

        return $transform;
    }
}
