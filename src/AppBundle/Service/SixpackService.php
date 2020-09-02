<?php
namespace AppBundle\Service;

use App\Experiments;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Stats;
use AppBundle\Document\DateTrait;
use AppBundle\Service\StatsService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The list of options/alternatives for each experiment has moved to App\Experiments (from the controllers)
 */
class SixpackService
{
    const TIMEOUT = 3;

    // assume conversion is occuring at user purchase (exclude existing policy holders)
    const LOG_MIXPANEL_CONVERSION = 'conversion';
    // always log to mixpanel
    const LOG_MIXPANEL_ALL = 'all';
    // don't log to mixpanel
    const LOG_MIXPANEL_NONE = 'none';

    // Exp 1
    const EXPERIMENT_HOMEPAGE_GREETING = 'Homepage Greeting';

    // Exp 2
    // const EXPERIMENT_PRICING_MESSAGING = 'Pricing Messaging';

    // Exp 3
    // const EXPERIMENT_EXIT_POPUP_MULTI = 'Exit Popup Multi';

    // Exp 4
    const EXPERIMENT_MANUFACTURER_PAGES_USPS = 'Manufacturers Landing Pages USPs';

    const ALTERNATIVES_SHARE_MESSAGE_SIMPLE = 'simple';

    const KPI_POLICY_PURCHASE = 'policy-purchase';

    const EXPIRED_EXPERIMENT_SHARE_MESSAGE = 'share-message';

    const EXPERIMENT_APP_SHARE_GAME = 'app-share-game';
    const ALTERNATIVES_APP_SHARE_GAME = 'share-game';
    const ALTERNATIVES_APP_SHARE_NOGAME = 'no-share-game';

    public static $archivedExperiments = [
        'homepage-aa',
        'quote-calc-lower',
        'landing-or-home',
        'cpc-quote-or-manufacturer',
        'homepage-phone-image',
        'quote-slider',
        'pyg-or-home',
        'quote-simple-complex-split',
        'quote-simple-split',
        'cpc-manufacturer-or-home',
        'cpc-manufacturer-with-home',
        'postcode',
        'homepage-v1-v2',
        'homepage-v1-v2old-v2new',
        'funnel-v1-v2',
        'homepage-v2-sticksearch-picsure',
        'homepage-v2-sticksearch-shuffle',
        'quote-sections',
        'policy-pdf-download',
        'cancellation',
        'new-quote-design',
        'cpc-manufacturer-old-new',
        'homepage-aa-v2',
        'step-3-payment-new',
        'purchase-flow-bacs',
        'cpc-quote-or-homepage',
        'purchase-funnel-dob-dropdown',
        'seventytwo-hours',
        'picsure-redesign',
        'new-welcome-modal',
        'competitor-landing',
        'trustpilot',
        'single-progressive-dropdown',
        'money-back-guarantee',
        'homepage-new-copy',
        'welcome-modal-requested-cancellation',
        'dropdown-search-mobile',
        'new-homepage-copy',
        'dropdown-search',
        'twentyfour-seventy-two',
        'new-content-with-nav',
        'phone-replacement-matching-advert',
        'starling-landing',
        'ad-landing-2',
        'homepage-new-copy',
        'bacs',
        'ebay-landing',
        'ebay-landing-1',
        'ebay-landing-2',
        'home-comparison',
        'homepage-usps',
        'scode-landing-text',
        'email-landing-text',
        'ad-landing-quotepage',
        'cta-yes-please',
        'app-share-method',
        'cta-buy-now',
        'burger-vs-full',
        'old-vs-new-funnel',
        'app-picsure-location',
        'app-request-picsure-location',
        'app-link-sms',
        'New Funnel',
        'New Funnel V2',
        'UK flag',
        'Homepage Tagline',
        'Pricing USP',
        'Homepage Tabs and USP',
        'Homepage Price USPs',
        'Hero Image',
        'Send Quote',
        'Amazon vs Money',
        'Quote Email Homepage',
        'Personalised Quote',
        'Personalised Quote V2',
        'Exit Popup',
        'Email Optional',
        'Hompage Copy Cheap vs Fair',
        'Homepage Copy Cheap vs Easy',
        'Pricing Messaging',
        'Exit Popup Multi'
    ];

    public static $unauthExperiments = [
        // Exp 1
        self::EXPERIMENT_HOMEPAGE_GREETING,
        // Exp 2
        // self::EXPERIMENT_PRICING_MESSAGING,
        // Exp 3
        // self::EXPERIMENT_EXIT_POPUP_MULTI,
        // Exp 4
        self::EXPERIMENT_MANUFACTURER_PAGES_USPS,
        // Exp 5
        // self::EXPERIMENT_COPY_CHEAP_VS_EASY,
    ];

    public static $authExperiments = [
        self::EXPERIMENT_APP_SHARE_GAME,
    ];

    public static $appExperiments = [
        self::EXPERIMENT_APP_SHARE_GAME => [
            self::ALTERNATIVES_APP_SHARE_GAME,
            self::ALTERNATIVES_APP_SHARE_NOGAME,
        ],
    ];

    public static function getAppParticipationByClientId()
    {
        return array_diff(
            array_intersect(self::$authExperiments, array_keys(self::$appExperiments)),
            []
        );
    }

    /**
     * For cases where there is only one conversion point (purchase)
     * @var array
     */
    public static $purchaseConversionSimple = [
    ];

    /**
     * For cases where there are multiple conversion points (e.g. convert on progress and later convert on purchase)
     * @var array
     */
    public static $purchaseConversionKpi = [
        // Exp 1
         self::EXPERIMENT_HOMEPAGE_GREETING,
        // Exp 2
        // self::EXPERIMENT_PRICING_MESSAGING,
        // Exp 3
        // self::EXPERIMENT_EXIT_POPUP_MULTI,
        // Exp 4
        self::EXPERIMENT_MANUFACTURER_PAGES_USPS,
        // Exp 5
        // self::EXPERIMENT_COPY_CHEAP_VS_EASY,
    ];

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $url;

    /** @var RequestService */
    protected $requestService;

    /** @var MixpanelService */
    protected $mixpanel;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $url
     * @param RequestService  $requestService
     * @param MixpanelService $mixpanel
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $url,
        RequestService $requestService,
        MixpanelService $mixpanel
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->url = $url;
        $this->requestService = $requestService;
        $this->mixpanel = $mixpanel;
    }

    public function getOptionsAvailable(string $testName)
    {
        return Experiments::optionsAvailable($testName);
    }

    public function runningSixpackExperiment(
        string $name,
        $logMixpanel = self::LOG_MIXPANEL_NONE,
        $trafficFraction = 1,
        $clientId = null,
        $force = null
    ) {
        $options = $this->getOptionsAvailable($name);

        // @todo replace with a direct call (or vice versa) to  self::runningExperiment(...)
        $experiment = $this->participate($name, $options, $logMixpanel, $trafficFraction, $clientId, $force);

        /*Request $request,
        $override = $request->get('force');
        if ($override && in_array($override, $options)) {
            $experiment = $override;
        }*/
        return $experiment;
    }

    public function participate(
        $experiment,
        $alternatives,
        $logMixpanel = self::LOG_MIXPANEL_NONE,
        $trafficFraction = 1,
        $clientId = null,
        $force = null
    ) {
        // default to first option
        $result = $alternatives[0];

        if ($this->requestService->isExcludedAnalytics()) {
            return $result;
        }

        try {
            if (!$clientId) {
                if (in_array($experiment, self::$unauthExperiments)) {
                    $clientId = $this->requestService->getTrackingId();
                } elseif (in_array($experiment, self::$authExperiments)) {
                    if ($this->requestService->getUser()) {
                        $clientId = $this->requestService->getUser()->getId();
                    }
                } else {
                    throw new \Exception(sprintf('Exp %s is not in auth or unauth array', $experiment));
                }
            }
            $data = [
                'experiment' => $experiment,
                'client_id' => $clientId,
                'traffic_fraction' => $trafficFraction,
            ];
            if ($force) {
                $data['force'] = $force;
                $data['record_force'] = "true";
            }
            $query = http_build_query($data);
            foreach ($alternatives as $alternative) {
                $query = sprintf("%s&alternatives=%s", $query, urlencode($alternative));
            }
            $url = sprintf('%s/participate?%s', $this->url, $query);
            $client = new Client();
            $res = $client->request('GET', $url, ['connect_timeout' => self::TIMEOUT, 'timeout' => self::TIMEOUT]);

            $body = (string) $res->getBody();
            $this->logger->info(sprintf('Sixpack participate response: %s', $body));

            // @codingStandardsIgnoreStart
            // {"status": "ok", "alternative": {"name": "red"}, "experiment": {"name": "button_color"}, "client_id": "12345678-1234-5678-1234-567812345678"}
            // @codingStandardsIgnoreEnd
            $data = json_decode($body, true);
            $result = $data['alternative']['name'];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed exp %s', $experiment), ['exception' => $e]);
        }
        $this->mixpanel->queueTrackWithUtm(
            MixpanelService::EVENT_SIXPACK,
            ['Experiment' => $experiment, 'Result' => $result]
        );
        $policyHolder = $this->requestService->getUser() && $this->requestService->getUser()->hasPolicy();
        if (($logMixpanel == self::LOG_MIXPANEL_CONVERSION && !$policyHolder) ||
            $logMixpanel == self::LOG_MIXPANEL_ALL) {
            if (in_array($experiment, self::$authExperiments)) {
                $this->mixpanel->queuePersonProperties(
                    ['Sixpack' => sprintf('%s=%s', $experiment, $result)],
                    false,
                    $this->requestService->getUser(),
                    true
                );
            } else {
                $this->mixpanel->queuePersonProperties(
                    ['Sixpack' => sprintf('%s=%s', $experiment, $result)],
                    false,
                    null,
                    true
                );
            }
        }

        return $result;
    }

    public function convert($experiment, $kpi = null, $expectParticipating = false)
    {
        $converted = false;
        if (in_array($experiment, self::$unauthExperiments)) {
            $converted = $this->convertByClientId($this->requestService->getTrackingId(), $experiment, $kpi);
        } elseif (in_array($experiment, self::$authExperiments)) {
            if ($this->requestService->getUser()) {
                $converted = $this->convertByClientId($this->requestService->getUser()->getId(), $experiment, $kpi);
            }
        } else {
            throw new \Exception(sprintf('Exp %s is not in auth or unauth array', $experiment));
        }

        if (!$converted && $expectParticipating) {
            if ($this->requestService->getUser()) {
                $this->logger->warning(sprintf(
                    'Expected participation in experiment %s for user %s',
                    $experiment,
                    $this->requestService->getUser()->getId()
                ));
            } else {
                $this->logger->warning(sprintf(
                    'Expected participation in experiment %s for anon user',
                    $experiment
                ));
            }
        }
    }

    public function convertByClientId($clientId, $experiment, $kpi = null)
    {
        if (!$clientId || mb_strlen($clientId) == 0) {
            $this->logger->info(sprintf('Missing clientId for experiment %s', $experiment));

            return;
        }
        try {
            $data = [
                'experiment' => $experiment,
                'client_id' => $clientId
            ];
            if ($kpi !== null) {
                $data['kpi'] = $kpi;
            }
            $query = http_build_query($data);
            $url = sprintf('%s/convert?%s', $this->url, $query);
            $client = new Client();
            $res = $client->request('GET', $url, ['connect_timeout' => self::TIMEOUT, 'timeout' => self::TIMEOUT]);

            $body = (string) $res->getBody();
            $this->logger->info(sprintf('Sixpack convert response: %s', $body));

            // @codingStandardsIgnoreStart
            // {"status": "ok", "alternative": {"name": "red"}, "experiment": {"name": "button_color"}, "conversion": {"kpi": null, "value": null}, "client_id": "12345678-1234-5678-1234-567812345678"}
            // @codingStandardsIgnoreEnd
            $data = json_decode($body, true);

            return true;
        } catch (ClientException $e) {
            $res = $e->getResponse();
            if (!$res) {
                $this->logger->error(sprintf('Failed converting exp %s', $experiment), ['exception' => $e]);
                return false;
            }
            $body = (string) $res->getBody();
            $data = json_decode($body, true);
            // {"status": "failed", "message": "this client was not participating"}
            // There appears to be an error with sixpack in use that is returning experiment does not exist
            // rather than this client was not participating
            // {"status": "failed", "message": "experiment does not exist"}
            if (mb_stripos($data['message'], 'not participating') !== false ||
                mb_stripos($data['message'], 'experiment does not exist') !== false) {
                return null;
            } else {
                $this->logger->error(sprintf('Failed converting exp %s', $experiment), ['exception' => $e]);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed converting exp %s', $experiment), ['exception' => $e]);
        }

        return false;
    }

    public function getText($experiment, $alternative, $data = null)
    {
        if ($experiment == self::EXPIRED_EXPERIMENT_SHARE_MESSAGE) {
            // Expected [0] => 'share link', [1] => 'share code'
            if (count($data) != 2) {
                return null;
            }

            if ($alternative == self::ALTERNATIVES_SHARE_MESSAGE_SIMPLE) {
                return sprintf("Join me on so-sure, really cheap and pretty clever %s", $data[0]);
            }
        }

        return null;
    }
}
