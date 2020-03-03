<?php
namespace AppBundle\Service;

use AppBundle\Exception\ExternalRateLimitException;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotPipeValidator;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use Predis\Client;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Id;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Stats;
use AppBundle\Document\Attribution;
use AppBundle\Repository\UserRepository;
use Mixpanel;
use UAParser\Parser;
use Jaam\Mixpanel\DataExportApi;
use Jaam\Mixpanel\DataExportApiException;
use CensusBundle\Service\SearchService;

class MixpanelService
{
    use ValidatorTrait;
    use DateTrait;

    const CACHE_TIME = 172800;

    const KEY_MIXPANEL_QUEUE = 'queue:mixpanel';
    const KEY_MIXPANEL_USER_EARLIEST_EVENTS = 'mixpanel:user:earlist-events';

    const ATTRIBUTION_PREFIX_CURRENT = '';
    const ATTRIBUTION_PREFIX_LATEST = 'Latest ';

    const USER_QUERY_HAS_EMAIL = 'query-has-email';
    const USER_QUERY_ALL = 'query-all';

    const QUEUE_PERSON_PROPERTIES = 'person';
    const QUEUE_PERSON_PROPERTIES_ONCE = 'person-once';
    const QUEUE_PERSON_PROPERTIES_UNION = 'person-union';
    const QUEUE_PERSON_INCREMENT = 'person-increment';
    const QUEUE_TRACK = 'track';
    const QUEUE_ALIAS = 'alias';
    const QUEUE_ATTRIBUTION = 'attribution';
    const QUEUE_DELETE = 'delete';
    const QUEUE_FREEZE_ATTRIBUTION = 'freeze-attribution';

    const QUEUE_TYPES = [
        self::QUEUE_PERSON_PROPERTIES,
        self::QUEUE_PERSON_PROPERTIES_ONCE,
        self::QUEUE_PERSON_PROPERTIES_UNION,
        self::QUEUE_PERSON_INCREMENT,
        self::QUEUE_TRACK,
        self::QUEUE_ALIAS,
        self::QUEUE_ATTRIBUTION,
        self::QUEUE_DELETE,
        self::QUEUE_FREEZE_ATTRIBUTION
    ];

    // Homepage
    const EVENT_HOME_PAGE = 'Home Page';
    const EVENT_QUOTE_PAGE = 'Quote Page';
    const EVENT_QUOTE_PAGE_PURCHASE = 'Quote Page Purchase Step';
    // Unused, but reserved
    const EVENT_MANUFACTURER_PAGE = 'Manufacturer Page';

    // Deprecated - use EVENT_CPC_QUOTE_PAGE
    const EVENT_LANDING_PAGE = 'Landing Page';

    const EVENT_CPC_QUOTE_PAGE = 'CPC Quote Page';
    const EVENT_CPC_MANUFACTURER_PAGE = 'CPC Manufacturer Page';
    const EVENT_CPC_COMPETITOR_PAGE = 'CPC Competitor Page';

    // Get details
    const EVENT_RECEIVE_DETAILS = 'Receive Personal Details';
    const EVENT_PURCHASE_POLICY = 'Purchase Policy';
    const EVENT_PAYMENT = 'Payment';
    const EVENT_INVITE = 'Invite someone';
    const EVENT_CONNECTION_COMPLETE = 'Connection Complete';
    const EVENT_BUY_BUTTON_CLICKED = 'Click on the Buy Now Button';
    const EVENT_POLICY_READY = 'Policy Ready For Purchase';
    const EVENT_COMPLETE_PLEDGE = 'Completed Pledge';
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
    const EVENT_ONBOARDING = 'Track Onboarding Interaction';
    const EVENT_POLICY_STATUS = 'Policy Status Change';
    const EVENT_PAYMENT_METHOD_CHANGED = 'Payment Method Changed';
    const EVENT_PROMO_PAGE = 'Promo Page';
    const EVENT_UPGRADE_POLICY = ' Policy Upgraded';

    const EVENT_EMAIL = 'Email Sent';
    const EVENT_SMS = 'Sms Sent';

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
        self::EVENT_QUOTE_PAGE_PURCHASE => Stats::MIXPANEL_QUOTES_UK,
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

    public static $trackedEvents = [
        self::EVENT_HOME_PAGE,
        self::EVENT_QUOTE_PAGE,
        self::EVENT_QUOTE_PAGE_PURCHASE,
        self::EVENT_MANUFACTURER_PAGE,
        self::EVENT_LANDING_PAGE,
        self::EVENT_CPC_QUOTE_PAGE,
        self::EVENT_CPC_MANUFACTURER_PAGE,
        self::EVENT_CPC_COMPETITOR_PAGE,
        self::EVENT_RECEIVE_DETAILS,
        self::EVENT_PURCHASE_POLICY,
        self::EVENT_PAYMENT,
        self::EVENT_INVITE,
        self::EVENT_CONNECTION_COMPLETE,
        self::EVENT_BUY_BUTTON_CLICKED,
        self::EVENT_POLICY_READY,
        self::EVENT_LOGIN,
        self::EVENT_APP_DOWNLOAD,
        self::EVENT_TEST ,
        self::EVENT_INVITATION_PAGE,
        self::EVENT_CANCEL_POLICY,
        self::EVENT_LEAD_CAPTURE,
        self::EVENT_CANCEL_POLICY_PAGE,
        self::EVENT_REQUEST_CANCEL_POLICY,
        self::EVENT_RENEWAL,
        self::EVENT_RENEW,
        self::EVENT_CASHBACK,
        self::EVENT_DECLINE_RENEW,
        self::EVENT_SIXPACK,
        self::EVENT_ONBOARDING,
        self::EVENT_POLICY_STATUS,
        self::EVENT_PAYMENT_METHOD_CHANGED,
        self::EVENT_EMAIL,
        self::EVENT_SMS,
        self::EVENT_PROMO_PAGE,
        self::EVENT_UPGRADE_POLICY
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

    /**
     * Removes all items of a given type from the mixpanel queue.
     * @param String $type is the type of items to remove and should be QUEUE_*.
     * @return int the number of items removed.
     */
    public function clearQueuedType($type)
    {
        $count = 0;
        $size = $this->redis->llen(self::KEY_MIXPANEL_QUEUE);
        for ($i = 0; $i < $size; $i++) {
            $item = $this->redis->lindex(self::KEY_MIXPANEL_QUEUE, 0);
            $data = unserialize($item);
            if ($data["action"] == $type) {
                $count++;
            } else {
                $this->redis->rpush(self::KEY_MIXPANEL_QUEUE, [$item]);
            }
            $this->redis->lpop(self::KEY_MIXPANEL_QUEUE);
        }
        return $count;
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_MIXPANEL_QUEUE, 0, $max);
    }

    public function countQueue()
    {
        return $this->redis->llen(self::KEY_MIXPANEL_QUEUE);
    }

    public function attribution($userId, $overrideAttribution = false)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->find($userId);

        return $this->attributionByUser($user, $overrideAttribution);
    }

    public function attributionByEmail($email, $overrideAttribution = false)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $this->attributionByUser($user, $overrideAttribution);
    }

    public function attributionByUser(User $user = null, $overrideAttribution = false)
    {
        if (!$user) {
            return;
        }

        list($firstAttribution, $lastAttribution) = $this->findAttributionsForUser($user);

        if ($firstAttribution) {
            // Only set the attribution if the user lacks one.
            if ($overrideAttribution || !$user->getAttribution()) {
                $user->setAttribution($firstAttribution);
            }
        }

        if ($lastAttribution) {
            $user->setLatestAttribution($lastAttribution);
        }

        $this->dm->flush();

        return $user;
    }

    public function getUserCount()
    {
        $query = [];
        $data = $this->mixpanelData->data('engage', $query);

        return $data['total'];
    }

    /**
     * Gets all users that have got multiple mixpanel accounts based on the email.
     * @return array containing the users.
     */
    public function findDuplicateUsers($useCache = false)
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        $emails = [];
        $users = [];

        $results = $this->findAllUsers(
            self::USER_QUERY_HAS_EMAIL,
            null,
            null,
            null,
            null,
            $useCache
        );

        foreach ($results as $result) {
            if (isset($result['$properties']['$email'])) {
                $email = $result['$properties']['$email'];
                if (array_key_exists($email, $emails)) {
                    if ($emails[$email] == 1) {
                        if ($user = $userRepo->findOneBy(['email' => $email])) {
                            $users[] = $user;
                        }
                    }
                    $emails[$email]++;
                } else {
                    $emails[$email] = 1;
                }
            }
        }

        return $users;
    }

    private function findAllUsers(
        $queryType,
        $data = null,
        $page = null,
        $session = null,
        $pageSize = null,
        $useCache = true
    ) {
        if ($queryType == self::USER_QUERY_HAS_EMAIL) {
            $query = ['where' => sprintf('defined(properties["$email"])')];
        } elseif ($queryType == self::USER_QUERY_ALL) {
            $query = [];
        } else {
            throw new \Exception('Unknown query type');
        }

        $key = sprintf('mixpanel:users:%s', $queryType);
        if ($this->redis->exists($key) && $useCache) {
            return unserialize($this->redis->get($key));
        }

        if (!$data) {
            $data = [];
        }

        if ($page) {
            $query['page'] = $page;
        }
        if ($session) {
            $query['session_id'] = $session;
        }
        $results = $this->mixpanelData->data('engage', $query);
        if (!$results || !$results['results']) {
            return $data;
        }
        // only set on the first page
        if (isset($results['page_size'])) {
            $pageSize = $results['page_size'];
        }
        $data = array_merge($data, $results['results']);

        if (count($results['results']) >= $pageSize) {
            return $this->findAllUsers(
                $queryType,
                $data,
                $results['page']+1,
                $results['session_id'],
                $pageSize
            );
        }

        $this->redis->setex($key, self::CACHE_TIME, serialize($data));

        return $data;
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

        $count += $this->deleteOldUsersByDays($days);
        $count += $this->deleteQuotePageMissingBotUserAgentUser();
        $count += $this->deleteHomepagePageMissingBotUserAgentUser();
        $count += $this->deleteNoValueUsers(14);
        //$count += $this->deleteOldUsersByNoEvents();
        $count += $this->deleteFacebookPreview();
        $count += $this->deleteSixpack();
        $count += $this->deleteHappyApp();
        $count += $this->deleteLittleValueUsers(30);

        return ['count' => $count, 'total' => $this->getUserCount()];
    }

    private function deleteOldUsersByDays($days)
    {
        $time = \DateTime::createFromFormat('U', time());
        $time = $time->sub(new \DateInterval(sprintf('P%dD', $days)));
        $query = [
            'where' => sprintf(
                'datetime(%s) > user["$last_seen"] or not defined (user["$last_seen"])',
                $time->format('U')
            ),
        ];

        return $this->runDelete($query);
    }

    private function deleteNoValueUsers($days = 14)
    {
        // users are primarily for attribution
        // if there is no attribution and no name, then there is little reason to keep around so long
        $time = \DateTime::createFromFormat('U', time());
        $time = $time->sub(new \DateInterval(sprintf('P%dD', $days)));
        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(datetime(%s) > user["$last_seen"] and not defined(user["$first_name"]) and not defined(user["$last_name"]) and not defined(user["Campaign Source"]) and not defined(user["Campaign Name"]) and not defined(user["Referer"]) and not defined(user["Attribution Invitation Method"]))',
                $time->format('U')
            )
            ];
        // @codingStandardsIgnoreEnd
        //print_r($query);

        return $this->runDelete($query);
    }

    private function deleteLittleValueUsers($days)
    {
        // users are primarily for attribution
        // if there is no attribution and no name, then there is little reason to keep around so long
        $time = \DateTime::createFromFormat('U', time());
        $time = $time->sub(new \DateInterval(sprintf('P%dD', $days)));
        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(datetime(%s) > user["$last_seen"] and not defined(user["$first_name"]) and not defined(user["$last_name"]) and not defined(user["Device Category"]) and user["Country"] != "United Kingdom")',
                $time->format('U')
            )
        ];
        // @codingStandardsIgnoreEnd
        //print_r($query);

        return $this->runDelete($query);
    }

    private function deleteQuotePageMissingBotUserAgentUser()
    {
        $time = \DateTime::createFromFormat('U', time());
        $time = $time->sub(new \DateInterval('P1D'));

        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(not defined(user["$last_name"]) and behaviors["behavior_11111"] > 0 and behaviors["behavior_11112"] == 0 and behaviors["behavior_11113"] == 0 and behaviors["behavior_11114"] == 0)'
            ),
            'behaviors' => [[
                "window" => "90d",
                "name" => "behavior_11111",
                "event_selectors" => [[
                    "event" => "Quote Page",
                    "selector" => "(\"Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0)\" in event[\"User Agent\"])"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11112",
                "event_selectors" => [[
                    "event" => "Home Page"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11113",
                "event_selectors" => [[
                    "event" => "Sixpack Experiment",
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11114",
                "event_selectors" => [[
                    "event" => "Click on the Buy Now Button"
                ]]
            ]
            ]];
        // @codingStandardsIgnoreEnd

        return $this->runDelete($query);
    }

    private function deleteHomepagePageMissingBotUserAgentUser()
    {
        $time = \DateTime::createFromFormat('U', time());
        $time = $time->sub(new \DateInterval('P1D'));

        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(not defined(user["$last_name"]) and behaviors["behavior_11111"] > 0 and behaviors["behavior_11112"] == 0 and behaviors["behavior_11113"] == 0 and behaviors["behavior_11114"] == 0)'
            ),
            'behaviors' => [[
                "window" => "90d",
                "name" => "behavior_11111",
                "event_selectors" => [[
                    "event" => "Home Page",
                    "selector" => "(\"Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0)\" in event[\"User Agent\"])"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11112",
                "event_selectors" => [[
                    "event" => "Quote Page"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11113",
                "event_selectors" => [[
                    "event" => "Sixpack Experiment",
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11114",
                "event_selectors" => [[
                    "event" => "Click on the Buy Now Button"
                ]]
            ]
            ]];
        // @codingStandardsIgnoreEnd

        return $this->runDelete($query);
    }

    private function deleteOldUsersByNoEvents()
    {
        // not certain about this one...
        $now = \DateTime::createFromFormat('U', time());
        // @codingStandardsIgnoreStart
        $query = [
            'selector' => sprintf(
                '(datetime(%s - 86400) > user["$last_seen"] and not defined(user["$last_name"]) and behaviors["behavior_11114"] == 0 and behaviors["behavior_11115"] == 0 and behaviors["behavior_11116"] == 0 and behaviors["behavior_11117"] == 0 and behaviors["behavior_11118"] == 0 and behaviors["behavior_11119"] == 0)',
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
            ], [
                "window" => "90d",
                "name" => "behavior_11118",
                "event_selectors" => [[
                    "event" => "Invitation Page"
                ]]
            ], [
                "window" => "90d",
                "name" => "behavior_11119",
                "event_selectors" => [[
                    "event" => "Invite Someone"
                ]]
            ]
            ]];
        // @codingStandardsIgnoreEnd
        //print_r($query);

        return $this->runDelete($query);
    }

    private function deleteFacebookPreview()
    {
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

        return $this->runDelete($query);
    }

    private function deleteSixpack()
    {
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

        return $this->runDelete($query);
    }

    private function deleteHappyApp()
    {
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

        return $this->runDelete($query);
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
                    $ignoreTime = isset($data['ignoreTime']) ? $data['ignoreTime'] : false;

                    $this->setPersonProperties($data['userId'], $data['properties'], $ip, $ignoreTime);
                } elseif ($action == self::QUEUE_PERSON_PROPERTIES_ONCE) {
                    if (!isset($data['userId']) || !isset($data['properties'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $ip = isset($data['ip']) ? $data['ip'] : null;
                    $ignoreTime = isset($data['ignoreTime']) ? $data['ignoreTime'] : false;

                    $this->setPersonPropertiesOnce($data['userId'], $data['properties'], $ip, $ignoreTime);
                } elseif ($action == self::QUEUE_PERSON_PROPERTIES_UNION) {
                    if (!isset($data['userId']) || !isset($data['properties'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $ip = isset($data['ip']) ? $data['ip'] : null;
                    $ignoreTime = isset($data['ignoreTime']) ? $data['ignoreTime'] : false;

                    $this->unionPersonProperties($data['userId'], $data['properties'], $ip, $ignoreTime);
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
                    $this->attribution($data['userId'], isset($data['override']) ? $data['override'] : false);
                } elseif ($action == self::QUEUE_DELETE) {
                    if (!isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }

                    $this->delete($data['userId']);
                } elseif ($action == self::QUEUE_FREEZE_ATTRIBUTION) {
                    if (!isset($data['userId'])) {
                        throw new \InvalidArgumentException(sprintf('Unknown message in queue %s', json_encode($data)));
                    }
                    $this->freezeAttribution($data['userId']);
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
            } catch (ExternalRateLimitException $e) {
                $data['processTime'] = time() + 60;
                $this->redis->rpush(self::KEY_MIXPANEL_QUEUE, serialize($data));
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

    public function queueAttribution(User $user, $overrideAttribution = false)
    {
        return $this->queue(self::QUEUE_ATTRIBUTION, $user->getId(), ['override' => $overrideAttribution]);
    }

    public function queue($action, $userId, $properties, $event = null, $retryAttempts = 0, $ignoreTime = false)
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
            'ignoreTime' => $ignoreTime,
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

    public function queuePersonProperties(
        array $personProperties,
        $setOnce = false,
        $user = null,
        $union = false,
        $ignoreTime = false
    ) {
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
            $this->queue(self::QUEUE_PERSON_PROPERTIES_ONCE, $userId, $personProperties, null, 0, $ignoreTime);
        } elseif ($union) {
            $this->queue(self::QUEUE_PERSON_PROPERTIES_UNION, $userId, $personProperties, null, 0, $ignoreTime);
        } else {
            $this->queue(self::QUEUE_PERSON_PROPERTIES, $userId, $personProperties, null, 0, $ignoreTime);
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

    private function setPersonProperties($userId, array $personProperties, $ip = null, $ignoreTime = false)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        /** @var \Producers_MixpanelPeople $people */
        $people = $this->mixpanel->people;
        $people->set($userId, $personProperties, $ip, $ignoreTime);
    }

    private function setPersonPropertiesOnce($userId, array $personProperties, $ip = null, $ignoreTime = false)
    {
        if (!$this->canSend()) {
            return;
        }

        //$this->mixpanel->identify($userId);
        /** @var \Producers_MixpanelPeople $people */
        $people = $this->mixpanel->people;
        $people->setOnce($userId, $personProperties, $ip, $ignoreTime);
    }

    private function unionPersonProperties($userId, array $personProperties, $ip = null, $ignoreTime = false)
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
            $people->append($userId, $key, $value, $ip, $ignoreTime);
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
            // TODO: this logic is temporary to stop old accounts without attribution date from getting overwritten.
            //       after 90 days (by june 2019) should be revert back and remove this if statement (keeping queue)
            if (!$user || !$user->getAttribution() || $user->getAttribution()->getDate() ||
                $user->getAttribution()->getGoCompareQuote()) {
                $this->queuePersonProperties($utm, true, $user);
            }
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

    /**
     * Adds freezing user attribution to the queue of mixpanel actions.
     * @param User $user is user whose attributes we must freeze.
     */
    public function queueFreezeAttribution($user)
    {
        $this->queue(self::QUEUE_FREEZE_ATTRIBUTION, $user->getId(), []);
    }

    /**
     * If the given user's attribution is null then it gets set to an empty attribution object so it will not be
     * edited in the future.
     * @param String $userId is the id of the user we are freezing.
     */
    public function freezeAttribution($userId)
    {
        $userRepository = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepository->find($userId);
        // If the user has gotten an attribution before this is run or if they don't exist then there is nothing to do.
        if (!$user || $user->getAttribution()) {
            return;
        }
        $attribution = new Attribution();
        $attribution->setCampaignSource(Attribution::SOURCE_UNTRACKED);
        $user->setAttribution($attribution);
        $this->dm->persist($attribution);
        $this->dm->flush();
    }

    private function transformUtm($setOnce = true)
    {
        $prefix = self::ATTRIBUTION_PREFIX_CURRENT;
        if (!$setOnce) {
            $prefix = self::ATTRIBUTION_PREFIX_LATEST;
        }

        $attribution = $this->requestService->getAttribution();

        return $attribution->getMixpanelProperties($prefix);
    }

    /**
     * Gets all events for a users
     * @param User $user     user to find
     * @param bool $useCache
     * @return array containing the response.
     * @throws \Exception when there is an error in the mixpanel query other than rate limiting.
     */
    protected function getUsersByEmail(User $user, $useCache = false)
    {
        $cacheKey = sprintf("mixpanel:user:%s", $user->getId());
        $results = null;
        if ($useCache) {
            if ($results = $this->redis->get($cacheKey)) {
                $results = unserialize($results);
            }
        }

        if (!$results) {
            $search = sprintf('(properties["$email"] == "%s")', $user->getEmailCanonical());
            try {
                $results = $this->mixpanelData->data('engage', ['where' => $search]);
                //print_r($results);
            } catch (\Exception $e) {
                if (mb_stripos($e->getMessage(), "rate limit") !== false) {
                    throw new ExternalRateLimitException("Mixpanel rate limit reached.");
                } else {
                    throw $e;
                }
            }

            $this->redis->setex($cacheKey, self::CACHE_TIME, serialize($results));
        }

        return $results;
    }

    public function findAttributionsForUser(User $user, $useCache = false)
    {
        /** @var Attribution $bestFirstAttribution */
        $firstAttribution = null;
        /** @var Attribution $bestLastAttribution */
        $lastAttribution = null;
        $results = $this->getUsersByEmail($user, $useCache);
        //print_r($results);
        foreach ($results['results'] as $result) {
            // Write in the data from the user account.
            if (mb_strtolower($result['$properties']['$email']) != $user->getEmailCanonical()) {
                throw new \Exception(
                    'Email mismatch %s / %s',
                    $result['$properties']['$email'],
                    $user->getEmailCanonical()
                );
            }

            if (is_array($result) && isset($result['$properties'])) {
                $currentFirstAttribution = new Attribution();
                $hasCurrentFirstAttribution = $currentFirstAttribution->setMixpanelProperties(
                    $result['$properties'],
                    self::ATTRIBUTION_PREFIX_CURRENT
                );
                //print $currentFirstAttribution;

                $currentLastAttribution = new Attribution();
                $hasCurrentLastAttribution = $currentLastAttribution->setMixpanelProperties(
                    $result['$properties'],
                    self::ATTRIBUTION_PREFIX_LATEST
                );
                //print $currentLastAttribution;

                // if we have a first attribution but not last, first & last are the same
                if ($hasCurrentFirstAttribution && !$hasCurrentLastAttribution) {
                    $currentLastAttribution = $currentFirstAttribution;
                    $hasCurrentFirstAttribution = true;
                }

                // if we have a last attribution but not first, first & last are the same
                if (!$hasCurrentFirstAttribution && $hasCurrentLastAttribution) {
                    $currentFirstAttribution = $currentLastAttribution;
                    $hasCurrentLastAttribution = true;
                }

                if ($hasCurrentFirstAttribution) {
                    if (!$firstAttribution || $firstAttribution->getDate() > $currentFirstAttribution->getDate()) {
                        $firstAttribution = $currentFirstAttribution;
                    }
                }

                if ($hasCurrentLastAttribution) {
                    if (!$lastAttribution || $lastAttribution->getDate() < $currentLastAttribution->getDate()) {
                        $lastAttribution = $currentLastAttribution;
                    }
                }

                //print $currentFirstAttribution . PHP_EOL;
                //print $currentLastAttribution . PHP_EOL;
            }
        }

        //print $firstAttribution . PHP_EOL;
        //print $lastAttribution . PHP_EOL;
        return [$firstAttribution, $lastAttribution];
    }

    /**
     * Use JQL to get an export of all events group by distinct_id with the min time
     * This will get us the earliest event for each person, which can be used as a best guess for the
     * first campaign source attribution date to populate this data as it was never initially set
     *
     * @param string $filename
     */
    public function importJqlQueryHistoricEvents($filename)
    {
        $data = file_get_contents($filename);
        $json = json_decode($data, true);
        $count = 0;
        foreach ($json as $item) {
            if (isset($item['key'][0])) {
                $guid = $item['key'][0];
                $date = \DateTime::createFromFormat('U', $item['value']/1000);
                if ($date >= new \DateTime('2017-01-01') && $date <= $this->now()) {
                    $this->redis->hset(self::KEY_MIXPANEL_USER_EARLIEST_EVENTS, $guid, $date->format('U'));
                    $count++;
                } else {
                    throw new \Exception(sprintf('Unknow date %s for %s', $item['value'], $guid));
                }
            }
        }

        return $count;
    }

    public function resetCampaignAttributionData($resetFirst = true, $resetLast = true, $maxNumberToProcess = null)
    {
        if ($resetFirst && !$this->redis->exists(self::KEY_MIXPANEL_USER_EARLIEST_EVENTS)) {
            throw new \Exception('Missing user events data');
        }
        $updated = [];
        $results = $this->findAllUsers(self::USER_QUERY_ALL);
        foreach ($results as $result) {
            $updateFirst = [];
            $id = $result['$distinct_id'];
            if ($resetFirst) {
                if ($this->redis->hexists(self::KEY_MIXPANEL_USER_EARLIEST_EVENTS, $id)) {
                    $date = \DateTime::createFromFormat(
                        'U',
                        $this->redis->hget(self::KEY_MIXPANEL_USER_EARLIEST_EVENTS, $id)
                    );
                    $updateFirst[sprintf('%sCampaign Attribution Date', self::ATTRIBUTION_PREFIX_CURRENT)] =
                        $date->format(\DateTime::ISO8601);

                    $this->queue(self::QUEUE_PERSON_PROPERTIES, $id, $updateFirst, null, 0, true);
                    //print_r($updateFirst);
                }
            }

            $updateLast = [];
            if ($resetLast) {
                $time = $result['$properties']['$last_seen'];

                $updateLast[sprintf('%sCampaign Attribution Date', self::ATTRIBUTION_PREFIX_LATEST)] =
                    $time;

                $this->queue(self::QUEUE_PERSON_PROPERTIES, $id, $updateLast, null, 0, true);
                //print_r($updateLast);
            }
            $updated[] = $id;

            if ($maxNumberToProcess && $maxNumberToProcess > 0 && $maxNumberToProcess >= count($updated)) {
                return $updated;
            }
        }


        return $updated;
    }
}
