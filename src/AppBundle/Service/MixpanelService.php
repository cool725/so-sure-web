<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Stats;
use AppBundle\Document\Attribution;
use Mixpanel;
use UAParser\Parser;
use Jaam\Mixpanel\DataExportApi;
use Jaam\Mixpanel\DataExportApiException;

class MixpanelService
{
    const KEY_MIXPANEL_QUEUE = 'queue:mixpanel';

    const QUEUE_PERSON_PROPERTIES = 'person';
    const QUEUE_PERSON_PROPERTIES_ONCE = 'person-once';
    const QUEUE_PERSON_INCREMENT = 'person-increment';
    const QUEUE_TRACK = 'track';
    const QUEUE_ALIAS = 'alias';
    const QUEUE_ATTRIBUTION = 'attribution';
    const QUEUE_DELETE = 'delete';

    const EVENT_HOME_PAGE = 'Home Page';
    const EVENT_QUOTE_PAGE = 'Quote Page';
    const EVENT_LANDING_PAGE = 'Landing Page';
    const EVENT_RECEIVE_DETAILS = 'Receive Personal Details';
    const EVENT_PURCHASE_POLICY = 'Purchase Policy';
    const EVENT_PAYMENT = 'Payment';
    const EVENT_INVITE = 'Invite someone';
    const EVENT_CONNECTION_COMPLETE = 'Connection Complete';
    const EVENT_BUY_BUTTON_CLICKED = 'Click on the Buy Now Button';
    const EVENT_POLICY_READY = 'Policy Ready For Purchase';
    const EVENT_LOGIN = 'Login';
    const EVENT_APP_DOWNLOAD = 'App Download';
    const EVENT_TEST = 'Tracking Test Event';
    const EVENT_INVITATION_PAGE = 'Invitation Page';
    const EVENT_CANCEL_POLICY = 'Cancel Policy';
    const EVENT_LEAD_CAPTURE = 'Lead Capture';

    const CUSTOM_TOTAL_SITE_VISITORS = '$custom_event:379938';
    const CUSTOM_QUOTE_PAGE_UK = '$custom_event:458980';
    const CUSTOM_LANDING_PAGE_UK = '$custom_event:443514';

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

    protected $mixpanelData;

    /** @var StatsService */
    protected $stats;

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $redis
     * @param Mixpanel        $mixpanel
     * @param RequestService  $requestService
     * @param string          $environment
     * @param string          $apiSecret
     * @param StatsService    $stats
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $redis,
        Mixpanel $mixpanel,
        RequestService $requestService,
        $environment,
        $apiSecret,
        $stats
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->mixpanel = $mixpanel;
        $this->requestService = $requestService;
        $this->environment = $environment;
        $this->mixpanelData = new DataExportApi($apiSecret);
        $this->stats = $stats;
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

        if ($this->environment == 'prod' &&
            ($this->requestService->isSoSureEmployee() ||
            $this->requestService->isExcludedAnalyticsIp())) {
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

    public function attribution($userId)
    {
        $repo = $this->dm->getRepository(User::class);
        $user = $repo->find($userId);

        return $this->attributionByUser($user);
    }

    public function attributionByEmail($email)
    {
        $repo = $this->dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => strtolower($email)]);

        return $this->attributionByUser($user);
    }

    public function attributionByUser(User $user = null)
    {
        $data = null;
        if (!$user) {
            return null;
        }
        $search = sprintf('(properties["$email"] == "%s")', $user->getEmailCanonical());
        $results = $this->mixpanelData->data('engage', [
            'where' => $search
        ]);
        foreach ($results['results'] as $result) {
            $data = $result['$properties'];
            if (strtolower($data['$email']) == $user->getEmailCanonical()) {
                $attribution = new Attribution();
                if (isset($data['Campaign Name'])) {
                    $attribution->setCampaignName(urldecode($data['Campaign Name']));
                }
                if (isset($data['Campaign Source'])) {
                    $attribution->setCampaignSource(urldecode($data['Campaign Source']));
                }
                if (isset($data['Campaign Medium'])) {
                    $attribution->setCampaignMedium(urldecode($data['Campaign Medium']));
                }
                if (isset($data['Campaign Term'])) {
                    $attribution->setCampaignTerm(urldecode($data['Campaign Term']));
                }
                if (isset($data['Campaign Content'])) {
                    $attribution->setCampaignContent(urldecode($data['Campaign Content']));
                }
                if (isset($data['Referer'])) {
                    $attribution->setReferer($data['Referer']);
                }
                $user->setAttribution($attribution);

                $latestAttribution = new Attribution();
                if (isset($data['Latest Campaign Name'])) {
                    $latestAttribution->setCampaignName(urldecode($data['Latest Campaign Name']));
                }
                if (isset($data['Latest Campaign Source'])) {
                    $latestAttribution->setCampaignSource(urldecode($data['Latest Campaign Source']));
                }
                if (isset($data['Latest Campaign Medium'])) {
                    $latestAttribution->setCampaignMedium(urldecode($data['Latest Campaign Medium']));
                }
                if (isset($data['Latest Campaign Term'])) {
                    $latestAttribution->setCampaignTerm(urldecode($data['Latest Campaign Term']));
                }
                if (isset($data['Latest Campaign Content'])) {
                    $latestAttribution->setCampaignContent(urldecode($data['Latest Campaign Content']));
                }
                if (isset($data['Latest Referer'])) {
                    $latestAttribution->setReferer($data['Latest Referer']);
                }
                $user->setLatestAttribution($latestAttribution);
                $this->dm->flush();
            }
        }

        return $data;
    }

    public function stats($start, $end)
    {
        $stats = [];
        $events = [
            self::CUSTOM_TOTAL_SITE_VISITORS,
            self::CUSTOM_QUOTE_PAGE_UK,
            self::CUSTOM_LANDING_PAGE_UK,
            self::EVENT_BUY_BUTTON_CLICKED,
            self::EVENT_RECEIVE_DETAILS,
            self::EVENT_INVITE
        ];
        $data = $this->mixpanelData->data('events', [
            'event' => $events,
            'type' => 'unique',
            'unit' => 'week',
            'from_date' => $start->format('Y-m-d'),
            'to_date' => $end->format('Y-m-d'),
        ]);
        $key = $data['data']['series'][0];
        foreach ($data['data']['values'] as $event => $results) {
            if ($event == self::CUSTOM_TOTAL_SITE_VISITORS) {
                $stats['Total Visitors'] = $results[$key];
                $this->stats->set(Stats::MIXPANEL_TOTAL_SITE_VISITORS, $start, $results[$key]);
            } elseif ($event == self::CUSTOM_QUOTE_PAGE_UK) {
                $stats['Quote Page UK'] = $results[$key];
                $this->stats->set(Stats::MIXPANEL_QUOTES_UK, $start, $results[$key]);
            } elseif ($event == self::CUSTOM_LANDING_PAGE_UK) {
                $stats['Landing Page UK'] = $results[$key];
                $this->stats->set(Stats::MIXPANEL_LANDING_UK, $start, $results[$key]);
            } elseif ($event == self::EVENT_BUY_BUTTON_CLICKED) {
                $stats['Click Buy Now Button'] = $results[$key];
                $this->stats->set(Stats::MIXPANEL_CLICK_BUY_NOW, $start, $results[$key]);
            } elseif ($event == self::EVENT_RECEIVE_DETAILS) {
                $stats['Receive Personal Details'] = $results[$key];
                $this->stats->set(Stats::MIXPANEL_RECEIVE_PERSONAL_DETAILS, $start, $results[$key]);
            } elseif ($event == self::EVENT_INVITE) {
                $stats['Invite someone'] = $results[$key];
                $this->stats->set(Stats::MIXPANEL_INVITE_SOMEONE, $start, $results[$key]);
            }
        }

        return $stats;
    }
    
    public function process($max)
    {
        $requeued = 0;
        $processed = 0;
        while ($processed + $requeued < $max) {
            $user = null;
            $data = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_MIXPANEL_QUEUE);
                if (!$queueItem) {
                    break;
                }
                $data = unserialize($queueItem);

                if (isset($data['action'])) {
                    $action = $data['action'];
                }

                // Requeue anything not yet ready to process
                $now = new \DateTime();
                if (isset($data['properties']) && isset($data['properties']['processTime'])
                    && $data['properties']['processTime'] > $now->format('U')) {
                    $requeued++;
                    $this->redis->rpush(self::KEY_MIXPANEL_QUEUE, serialize($data));
                    continue;
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
                } elseif ($action == self::QUEUE_ATTRIBUTION) {
                    if (!isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->attribution($data['userId']);
                } elseif ($action == self::QUEUE_DELETE) {
                    if (!isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->delete($data['userId']);
                } else {
                    throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $processed++;
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

        return ['processed' => $processed, 'requeued' => $requeued];
    }

    public function queueAttribution(User $user)
    {
        return $this->queue(self::QUEUE_ATTRIBUTION, $user->getId(), []);
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
        if ($user->getFacebookId()) {
            $userData['Facebook'] = true;
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
            $userData['Policy Status'] = $policy->getStatus();
            if ($connection = $policy->getLastConnection()) {
                $userData['Last connection complete'] = $connection->getDate()->format(\DateTime::ATOM);
            }
            if ($policy->getStatus() == Policy::STATUS_CANCELLED) {
                $userData['Cancellation Reason'] = $policy->getCancelledReason();
            }
            if ($policy->getStart()) {
                $diff = $policy->getStart()->getTimestamp() - $policy->getUser()->getCreated()->getTimestamp();
                $userData['Minutes to Final Purchase'] = round($diff / 60);
            }
            $diff = $policy->getCreated()->getTimestamp() - $policy->getUser()->getCreated()->getTimestamp();
            $userData['Minutes to Start Purchase'] = round($diff / 60);
        }

        $this->queuePersonProperties($userData, false, $user);
        $this->queueAttribution($user);
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

        if (stripos($userAgentDetails->ua->family, 'bot') !== false) {
            return false;
        }
        if (stripos($userAgentDetails->ua->family, 'spider') !== false) {
            return false;
        }
        if (stripos($userAgentDetails->ua->family, 'crawler') !== false) {
            return false;
        }

        // exclude bots from tracking
        if (in_array($userAgentDetails->ua->family, [
            'PhantomJS',
            'Yahoo! Slurp',
            'Apache-HttpClient',
            'Java',
            'Python Requests',
            'Python-urllib',
            'Scrapy',
            'Google',
            'ia_archiver',
            'SimplePie',
        ])) {
            return false;
        }

        if (stripos($userAgent, 'StatusCake') !== false) {
            return false;
        }
        if (stripos($userAgent, 'okhttp') !== false) {
            return false;
        }
        if (stripos($userAgent, 'curl') !== false) {
            return false;
        }
        if (stripos($userAgent, 'ips-agent') !== false) {
            return false;
        }
        if (stripos($userAgent, 'ScoutJet') !== false) {
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
            $utmLatest = $this->transformUtm(false);
            $this->queuePersonProperties($utmLatest, false, $user);

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
        if ($event == self::EVENT_TEST &&
            isset($properties['Test Name']) &&
            $properties['Test Name'] == "Watch Video") {
            $this->queuePersonProperties(['Watch Video' => true], false, $user);
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

    public function queueDelete($userId)
    {
        $processTime = new \DateTime();
        $processTime = $processTime->add(new \DateInterval('PT2M'));
        $this->queue(self::QUEUE_DELETE, $userId, ['processTime' => $processTime->format('U')]);
    }

    public function delete($id)
    {
        $this->mixpanel->people->deleteUser($id);
    }

    private function transformUtm($setOnce = true)
    {
        $prefix = '';
        if (!$setOnce) {
            $prefix = 'Latest ';
        }
        $utm = $this->requestService->getUtm();
        $referer = $this->requestService->getReferer();

        $transform = [];
        if ($utm) {
            if (isset($utm['source']) && $utm['source']) {
                $transform[sprintf('%sCampaign Source', $prefix)] = $utm['source'];
            }
            if (isset($utm['medium']) && $utm['medium']) {
                $transform[sprintf('%sCampaign Medium', $prefix)] = $utm['medium'];
            }
            if (isset($utm['campaign']) && $utm['campaign']) {
                $transform[sprintf('%sCampaign Name', $prefix)] = $utm['campaign'];
            }
            if (isset($utm['term']) && $utm['term']) {
                $transform[sprintf('%sCampaign Term', $prefix)] = $utm['term'];
            }
            if (isset($utm['content']) && $utm['content']) {
                $transform[sprintf('%sCampaign Content', $prefix)] = $utm['content'];
            }
        }

        if ($referer) {
            $refererDomain = parse_url($referer, PHP_URL_HOST);
            $currentDomain = parse_url($this->requestService->getUri(), PHP_URL_HOST);
            if (strtolower($refererDomain) != strtolower($currentDomain)) {
                $transform[sprintf('%sReferer', $prefix)] = $referer;
            }
        }

        return $transform;
    }
}
