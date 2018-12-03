<?php
namespace AppBundle\Service;

use AppBundle\Document\ValidatorTrait;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotPipeValidator;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use Predis\Client;
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
use CensusBundle\Service\SearchService;

class MixpanelService
{
    use ValidatorTrait;

    const KEY_MIXPANEL_QUEUE = 'queue:mixpanel';

    const QUEUE_PERSON_PROPERTIES = 'person';
    const QUEUE_PERSON_PROPERTIES_ONCE = 'person-once';
    const QUEUE_PERSON_PROPERTIES_UNION = 'person-union';
    const QUEUE_PERSON_INCREMENT = 'person-increment';
    const QUEUE_TRACK = 'track';
    const QUEUE_ALIAS = 'alias';
    const QUEUE_ATTRIBUTION = 'attribution';
    const QUEUE_DELETE = 'delete';

    const EVENT_HOME_PAGE = 'Home Page';
    const EVENT_QUOTE_PAGE = 'Quote Page';
    // Unused, but reserved
    const EVENT_MANUFACTURER_PAGE = 'Manufacturer Page';

    // Deprecated - use EVENT_CPC_QUOTE_PAGE
    const EVENT_LANDING_PAGE = 'Landing Page';

    const EVENT_CPC_QUOTE_PAGE = 'CPC Quote Page';
    const EVENT_CPC_MANUFACTURER_PAGE = 'CPC Manufacturer Page';
    const EVENT_CPC_COMPETITOR_PAGE = 'CPC Competitor Page';
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
    const EVENT_CANCEL_POLICY_PAGE = 'Cancel Policy Page';
    const EVENT_REQUEST_CANCEL_POLICY = 'Request Cancel Policy';
    const EVENT_RENEWAL = 'Renewal Page';
    const EVENT_RENEW = 'Renew Policy';
    const EVENT_CASHBACK = 'Cashback';
    const EVENT_DECLINE_RENEW = 'Decline Renew Policy';
    const EVENT_SIXPACK = 'Sixpack Experiment';
    const EVENT_POLICY_STATUS = 'Policy Status Change';
    const EVENT_PAYMENT_METHOD_CHANGED = 'Payment Method Changed';

    const EVENT_EMAIL = 'Email Sent';

    const CUSTOM_TOTAL_SITE_VISITORS = '$custom_event:379938';
    const CUSTOM_QUOTE_PAGE_UK = '$custom_event:458980';

    // Moved to CUSTOM_CPC_QUOTE_PAGE_UK mid June 2017
    const CUSTOM_LANDING_PAGE_UK = '$custom_event:443514';
    const CUSTOM_CPC_QUOTE_PAGE_UK = '$custom_event:534870';

    const CUSTOM_CPC_MANUFACTURER_PAGE_UK = '$custom_event:534868';
    const CUSTOM_CPC_COMPETITOR_PAGE_UK = '$custom_event:599266';
    //const CUSTOM_INVITATION_PAGE_SCODE = '$custom_event:591840';
    //const CUSTOM_INVITATION_PAGE_EMAIL = '$custom_event:591838';
    const CUSTOM_INVITATION_PAGE_SMS = '$custom_event:591838';
    const CUSTOM_PURCHASE_POLICY_APP_ATTRIB = '$custom_event:674827';

    public static $events = [
        self::CUSTOM_TOTAL_SITE_VISITORS => Stats::MIXPANEL_TOTAL_SITE_VISITORS,
        self::CUSTOM_QUOTE_PAGE_UK => Stats::MIXPANEL_QUOTES_UK,
        self::CUSTOM_LANDING_PAGE_UK => Stats::MIXPANEL_LANDING_UK,
        self::EVENT_BUY_BUTTON_CLICKED => Stats::MIXPANEL_CLICK_BUY_NOW,
        self::EVENT_RECEIVE_DETAILS => Stats::MIXPANEL_RECEIVE_PERSONAL_DETAILS,
        self::EVENT_POLICY_READY => Stats::MIXPANEL_POLICY_READY,
        self::EVENT_PURCHASE_POLICY => Stats::MIXPANEL_PURCHASE_POLICY,
        self::EVENT_INVITE => Stats::MIXPANEL_INVITE_SOMEONE,
        self::CUSTOM_CPC_QUOTE_PAGE_UK => Stats::MIXPANEL_CPC_QUOTES_UK,
        self::CUSTOM_CPC_MANUFACTURER_PAGE_UK => Stats::MIXPANEL_CPC_MANUFACTURER_UK,
        self::CUSTOM_CPC_COMPETITOR_PAGE_UK => Stats::MIXPANEL_CPC_COMPETITORS_UK,
        self::CUSTOM_PURCHASE_POLICY_APP_ATTRIB => Stats::MIXPANEL_PURCHASE_POLICY_APP_ATTRIB,
    ];

    public static $campaignSources = [
        'google' => 'google',
        'Google' => 'google',
        'Facebook' => 'facebook',
        'facebook' => 'facebook',
        'Instagram' => 'instagram',
        'bing' => 'bing',
        'PYG' => 'pyg',
        'money.co.uk' => 'money',
        //'Affiliate',
        //'MoneySupermarket',
    ];

    public static function getCampaignSources($event)
    {
        $data = [];
        foreach (self::$campaignSources as $campaignSource => $extension) {
            $data[$event]['Campaign Source'][$campaignSource] = sprintf('%s-%s', self::$events[$event], $extension);
        }

        return $data;
    }

    public static function getSegments()
    {
        $data = array_merge(
            [self::EVENT_INVITATION_PAGE => [
                'Invitation Method' => [
                    'scode' => Stats::MIXPANEL_VIEW_INVITATION_SCODE,
                    'sms' => Stats::MIXPANEL_VIEW_INVITATION_SMS,
                    'email' => Stats::MIXPANEL_VIEW_INVITATION_EMAIL,
                ]
            ]],
            self::getCampaignSources(self::CUSTOM_TOTAL_SITE_VISITORS),
            self::getCampaignSources(self::CUSTOM_QUOTE_PAGE_UK),
            self::getCampaignSources(self::EVENT_BUY_BUTTON_CLICKED),
            self::getCampaignSources(self::EVENT_RECEIVE_DETAILS),
            self::getCampaignSources(self::EVENT_POLICY_READY),
            self::getCampaignSources(self::EVENT_PURCHASE_POLICY)
        );

        //print_r($data);
        return $data;
    }

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client  */
    protected $redis;

    /** @var Mixpanel */
    protected $mixpanel;

    /** @var RequestService */
    protected $requestService;

    protected $mixpanelData;

    /** @var StatsService */
    protected $stats;

    /** @var SearchService */
    protected $searchService;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param Client          $redis
     * @param Mixpanel        $mixpanel
     * @param RequestService  $requestService
     * @param string          $apiSecret
     * @param StatsService    $stats
     * @param SearchService   $searchService
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        Client $redis,
        Mixpanel $mixpanel,
        RequestService $requestService,
        $apiSecret,
        $stats,
        SearchService $searchService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->mixpanel = $mixpanel;
        $this->requestService = $requestService;
        $this->mixpanelData = new DataExportApi($apiSecret);
        $this->stats = $stats;
        $this->searchService = $searchService;
    }

    private function canSend()
    {
        if ($this->requestService->isExcludedAnalytics()) {
            return false;
        }

        return true;
    }

    public function clearQueue()
    {
        $this->redis->del([self::KEY_MIXPANEL_QUEUE]);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_MIXPANEL_QUEUE, 0, $max);
    }

    public function countQueue()
    {
        return $this->redis->llen(self::KEY_MIXPANEL_QUEUE);
    }

    public function attribution($userId)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->find($userId);

        return $this->attributionByUser($user);
    }

    public function attributionByEmail($email)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

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
            if (mb_strtolower($data['$email']) == $user->getEmailCanonical()) {
                $attribution = new Attribution();
                $dataPresent = false;
                if (isset($data['Campaign Name'])) {
                    $attribution->setCampaignName($this->conformAlphanumericSpaceDotPipe(
                        urldecode($data['Campaign Name']),
                        250
                    ));
                    $dataPresent = true;
                }
                if (isset($data['Campaign Source'])) {
                    $attribution->setCampaignSource($this->conformAlphanumericSpaceDot(
                        urldecode($data['Campaign Source']),
                        250
                    ));
                    $dataPresent = true;
                }
                if (isset($data['Campaign Medium'])) {
                    $attribution->setCampaignMedium($this->conformAlphanumericSpaceDot(
                        urldecode($data['Campaign Medium']),
                        250
                    ));
                    $dataPresent = true;
                }
                if (isset($data['Campaign Term'])) {
                    $attribution->setCampaignTerm($this->conformAlphanumericSpaceDot(
                        urldecode($data['Campaign Term']),
                        250
                    ));
                    $dataPresent = true;
                }
                if (isset($data['Campaign Content'])) {
                    $attribution->setCampaignContent($this->conformAlphanumericSpaceDot(
                        urldecode($data['Campaign Content']),
                        250
                    ));
                    $dataPresent = true;
                }
                if (isset($data['Referer'])) {
                    $attribution->setReferer($data['Referer']);
                    $dataPresent = true;
                }
                if (isset($data['Device Category'])) {
                    $attribution->setDeviceCategory($data['Device Category']);
                    $dataPresent = true;
                }
                if (isset($data['Device OS'])) {
                    $attribution->setDeviceOS($data['Device OS']);
                    $dataPresent = true;
                }

                if ($dataPresent) {
                    $user->setAttribution($attribution);
                }

                $latestAttribution = new Attribution();
                $dataPresent = false;
                if (isset($data['Latest Campaign Name'])) {
                    $latestAttribution->setCampaignName($this->conformAlphanumericSpaceDotPipe(
                        urldecode($data['Latest Campaign Name']),
                        250
                    ));
                    $dataPresent = true;
                }
                if (isset($data['Latest Campaign Source'])) {
                    $latestAttribution->setCampaignSource(urldecode($data['Latest Campaign Source']));
                    $dataPresent = true;
                }
                if (isset($data['Latest Campaign Medium'])) {
                    $latestAttribution->setCampaignMedium(urldecode($data['Latest Campaign Medium']));
                    $dataPresent = true;
                }
                if (isset($data['Latest Campaign Term'])) {
                    $latestAttribution->setCampaignTerm(urldecode($data['Latest Campaign Term']));
                    $dataPresent = true;
                }
                if (isset($data['Latest Campaign Content'])) {
                    $latestAttribution->setCampaignContent(urldecode($data['Latest Campaign Content']));
                    $dataPresent = true;
                }
                if (isset($data['Latest Referer'])) {
                    $latestAttribution->setReferer($data['Latest Referer']);
                    $dataPresent = true;
                }
                if (isset($data['Latest Device Category'])) {
                    $latestAttribution->setDeviceCategory($data['Latest Device Category']);
                    $dataPresent = true;
                }
                if (isset($data['Latest Device OS'])) {
                    $latestAttribution->setDeviceOS($data['Latest Device OS']);
                    $dataPresent = true;
                }
                if ($dataPresent) {
                    $user->setLatestAttribution($latestAttribution);
                }
                $this->dm->flush();
            }
        }

        return $data;
    }

    public function getUserCount()
    {
        $query = [
        ];
        $data = $this->mixpanelData->data('engage', $query);

        return $data['total'];
    }

    private function runDelete($query)
    {
        $data = $this->mixpanelData->data('engage', $query);
        $count = 0;
        if ($data) {
            foreach ($data['results'] as $user) {
                $this->queueDelete($user['$distinct_id'], 0);
                $count++;
            }
        }

        return $count;
    }

    private function runCount($query)
    {
        $data = $this->mixpanelData->data('engage', $query);

        return $data['total'];
    }

    public function deleteOldUsers($days = null)
    {
        if (!$days) {
            $days = 90;
        }
        $data = null;
        $count = 0;

        $time = \DateTime::createFromFormat('U', time());
        $time = $time->sub(new \DateInterval(sprintf('P%dD', $days)));
        $query = [
            'where' => sprintf(
                'datetime(%s) > user["$last_seen"] or not defined (user["$last_seen"])',
                $time->format('U')
            ),
        ];
        $count += $this->runDelete($query);

        // Although facebook should be allowed, there seems to be a 'preview' mode which causes havoc
        // with our sixpack tests and causes a huge increase (30k+ users over a few week period)
        // so delete any users over 1 day old with a facebook brower that have just 1 sixpack experiment
        $now = \DateTime::createFromFormat('U', time());
        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(behaviors["behavior_11111"] == 1 and datetime(%s - 86400) > user["$last_seen"] and behaviors["behavior_11112"] == 0 and behaviors["behavior_11113"] == 0)',
                $now->format('U')
            ),
            'behaviors' => [[
                "window" => "90d",
                "name" => "behavior_11111",
                "event_selectors" => [[
                    "event" => "Sixpack Experiment",
                    "selector" => "((\"Facebook\" in event[\"\$browser\"]) and (defined (event[\"\$browser\"])))"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11112",
                "event_selectors" => [[
                    "event" => "Login"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11113",
                "event_selectors" => [[
                    "event" => "Click on the Buy Now Button"
                ]]
            ]
        ]];
        // @codingStandardsIgnoreEnd
        $count += $this->runDelete($query);

        // Although facebook should be allowed, there seems to be a 'preview' mode which causes havoc
        // with our sixpack tests and causes a huge increase (30k+ users over a few week period)
        // so delete any users over 1 day old with a facebook brower that have just 1 sixpack experiment
        $now = \DateTime::createFromFormat('U', time());
        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(behaviors["behavior_11114"] == 1 and datetime(%s - 86400) > user["$last_seen"] and not defined(user["$last_name"]) and behaviors["behavior_11115"] == 0 and behaviors["behavior_11116"] == 0 and behaviors["behavior_11117"] == 0)',
                $now->format('U')
            ),
            'behaviors' => [[
                "window" => "90d",
                "name" => "behavior_11114",
                "event_selectors" => [[
                    "event" => "Sixpack Experiment",
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11115",
                "event_selectors" => [[
                    "event" => "Home Page"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11116",
                "event_selectors" => [[
                    "event" => "Quote Page"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11117",
                "event_selectors" => [[
                    "event" => "CPC Manufacturer Page"
                ]]
            ]
            ]];
        // @codingStandardsIgnoreEnd
        //print_r($query);
        $count += $this->runDelete($query);

        // Change in behaviour - temporarily delete happy app user agent
        $now = \DateTime::createFromFormat('U', time());
        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(datetime(%s - 86400) > user["$last_seen"] and behaviors["behavior_11118"] == 1)',
                $now->format('U')
            ),
            'behaviors' => [[
                "window" => "90d",
                "name" => "behavior_11118",
                "event_selectors" => [[
                    "event" => "Home Page",
                    "selector" => "(\"HappyApps\" in event[\"User Agent\"])"
                ]]
            ]
            ]];
        // @codingStandardsIgnoreEnd
        $count += $this->runDelete($query);
        //$count += $this->runCount($query);

        return ['count' => $count, 'total' => $data['total']];
    }

    public function stats($start, $end)
    {
        $stats = [];
        $data = $this->mixpanelData->data('events', [
            'event' => array_keys(self::$events),
            'type' => 'unique',
            'unit' => 'week',
            'from_date' => $start->format('Y-m-d'),
            'to_date' => $end->format('Y-m-d'),
        ]);
        $key = $data['data']['series'][0];
        foreach ($data['data']['values'] as $event => $results) {
            $stats[self::$events[$event]] = $results[$key];
        }

        foreach (self::getSegments() as $event => $segmentData) {
            foreach ($segmentData as $segment => $mapping) {
                $query = [
                    'event' => $event,
                    'on' => sprintf('properties["%s"]', $segment),
                    'type' => 'unique',
                    'unit' => 'week',
                    'from_date' => $start->format('Y-m-d'),
                    'to_date' => $end->format('Y-m-d'),
                ];
                $data = $this->mixpanelData->data('segmentation', $query);
                //print_r($data);
                $key = $data['data']['series'][0];
                foreach ($data['data']['values'] as $on => $results) {
                    foreach ($mapping as $type => $statsKey) {
                        if ($on == $type) {
                            if (!isset($stats[$statsKey])) {
                                $stats[$statsKey] = 0;
                            }
                            $stats[$statsKey] += $results[$key];
                        }
                    }
                }
            }
        }

        foreach ($stats as $key => $value) {
            $this->stats->set($key, $start, $value);
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

                $action = null;
                if (isset($data['action'])) {
                    $action = $data['action'];
                }

                // Requeue anything not yet ready to process
                $now = \DateTime::createFromFormat('U', time());
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
                } elseif ($action == self::QUEUE_PERSON_PROPERTIES_UNION) {
                    if (!isset($data['userId']) || !isset($data['properties'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $ip = isset($data['ip']) ? $data['ip'] : null;

                    $this->unionPersonProperties($data['userId'], $data['properties'], $ip);
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
            $now = \DateTime::createFromFormat('U', time());
            $properties = array_merge($properties, ['time' => $now->getTimestamp()]);
        }

        $xHeaders = $this->requestService->getAllXHeaders();
        if ($xHeaders && count($xHeaders) > 0) {
            $properties = array_merge($properties, ['xheaders' => $xHeaders]);
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

    public function queuePersonProperties(array $personProperties, $setOnce = false, $user = null, $union = false)
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
        } elseif ($union) {
            $this->queue(self::QUEUE_PERSON_PROPERTIES_UNION, $userId, $personProperties);
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

        /** @var \Producers_MixpanelPeople $people */
        $people = $this->mixpanel->people;
        $people->increment($userId, $field, $incrementBy, null, true);
    }

    private function setPersonProperties($userId, array $personProperties, $ip = null)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        /** @var \Producers_MixpanelPeople $people */
        $people = $this->mixpanel->people;
        $people->set($userId, $personProperties, $ip);
    }

    private function setPersonPropertiesOnce($userId, array $personProperties, $ip = null)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        /** @var \Producers_MixpanelPeople $people */
        $people = $this->mixpanel->people;
        $people->setOnce($userId, $personProperties, $ip);
    }

    private function unionPersonProperties($userId, array $personProperties, $ip = null)
    {
        if (!$this->canSend()) {
            return;
        }

        foreach ($personProperties as $key => $value) {
            // odd behvaoiur in php array where if value is array, then union is used, otherwise append
            // as we're always wanting union for this operation, ensure array is passed
            if (gettype($value) != "array") {
                $value = [$value];
            }
            /** @var \Producers_MixpanelPeople $people */
            $people = $this->mixpanel->people;
            $people->append($userId, $key, $value, $ip);
        }
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
        if ($user->getGender()) {
            $userData['Gender'] = $user->getGender();
        }
        if ($user->getBirthday()) {
            $userData['Date of Birth'] = $user->getBirthday()->format(\DateTime::ATOM);
        }
        if ($user->getBillingAddress()) {
            $userData['Billing Address'] = $user->getBillingAddress()->__toString();
            if ($census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode())) {
                $userData['PenPortrait'] = $census->getSubGroup();
            }
            if ($income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode())) {
                $userData['Total Weekly Income'] = $income->getTotal()->getIncome();
            }
        }
        if ($user->getFacebookId()) {
            $userData['Facebook'] = true;
        }

        $analytics = $user->getAnalytics();
        $userData['Number of Policies'] = $analytics['numberPolicies'];
        $userData['Number of Payments Received'] = $analytics['paymentsReceived'];
        $userData['Number of Connections'] = $analytics['connections'];
        $userData['Reward Pot Value'] = $analytics['rewardPot'];
        $userData['Payment Method'] = $analytics['paymentMethod'];
        if (isset($analytics['devices'])) {
            $userData['Insured Devices'] = join(';', $analytics['devices']);
        }
        if ($analytics['os']) {
            $userData['OS'] = $analytics['os'];
        }
        if ($analytics['lastPaymentReceived']) {
            $userData['Last payment received'] = $analytics['lastPaymentReceived']->format(\DateTime::ATOM);
        }
        if ($analytics['lastConnection']) {
            $userData['Last connection complete'] = $analytics['lastConnection']->format(\DateTime::ATOM);
        }

        if ($analytics['firstPolicy']['minutesStartPurchase']) {
            $userData['Minutes to Start Purchase'] = $analytics['firstPolicy']['minutesStartPurchase'];
        }
        if ($analytics['firstPolicy']['minutesFinalPurchase']) {
            $userData['Minutes to Final Purchase'] = $analytics['firstPolicy']['minutesFinalPurchase'];
        }
        if ($analytics['firstPolicy']['monthlyPremium']) {
            $userData['Final Monthly Cost'] = $analytics['firstPolicy']['monthlyPremium'];
        }

        $this->queuePersonProperties($userData, false, $user);
        if ($analytics['connectedWithFacebook']) {
            $this->queuePersonProperties(['Connected With' => 'Facebook'], false, $user, true);
        }
        if ($analytics['connectedWithGoogle']) {
            $this->queuePersonProperties(['Connected With' => 'Google'], false, $user, true);
        }
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
            $properties['Device Category'] = $this->requestService->getDeviceCategory();
            $properties['Device OS'] = $this->requestService->getDeviceOS();
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

    public function queueDelete($userId, $delayMinutes = null)
    {
        if ($delayMinutes === null) {
            $delayMinutes = 2;
        }
        $processTime = \DateTime::createFromFormat('U', time());
        if ($delayMinutes > 0) {
            $processTime = $processTime->add(new \DateInterval(sprintf('PT%dM', $delayMinutes)));
        }
        $this->queue(self::QUEUE_DELETE, $userId, ['processTime' => $processTime->format('U')]);
    }

    public function delete($id)
    {
        /** @var \Producers_MixpanelPeople $people */
        $people = $this->mixpanel->people;
        $people->deleteUser($id);
    }

    private function transformUtm($setOnce = true)
    {
        $prefix = '';
        if (!$setOnce) {
            $prefix = 'Latest ';
        }
        $utm = $this->requestService->getUtm();
        $referer = $this->requestService->getReferer();
        $deviceCategory = null;
        $deviceOS = null;
        if ($userAgent = $this->requestService->getUserAgent()) {
            $parser = Parser::create();
            $userAgentDetails = $parser->parse($userAgent);
            $deviceCategory = $this->requestService->getDeviceCategory();
            $deviceOS = $this->requestService->getDeviceOS();
        }

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
            if (mb_strtolower($refererDomain) != mb_strtolower($currentDomain)) {
                $transform[sprintf('%sReferer', $prefix)] = $referer;
            }
        }

        if ($deviceCategory) {
            $transform[sprintf('%sDevice Category', $prefix)] = $deviceCategory;
        }

        if ($deviceOS) {
            $transform[sprintf('%sDevice OS', $prefix)] = $deviceOS;
        }

        return $transform;
    }
}
