<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Stats;
use AppBundle\Document\DateTrait;
use AppBundle\Service\StatsService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class SixpackService
{
    const TIMEOUT = 3;

    // assume conversion is occuring at user purchase (exclude existing policy holders)
    const LOG_MIXPANEL_CONVERSION = 'conversion';
    // always log to mixpanel
    const LOG_MIXPANEL_ALL = 'all';
    // don't log to mixpanel
    const LOG_MIXPANEL_NONE = 'none';

    const EXPERIMENT_HOMEPAGE_AA_V2 = 'homepage-aa-v2';
    const EXPERIMENT_APP_SHARE_METHOD = 'app-share-method';
    const EXPERIMENT_APP_PICSURE_LOCATION = 'app-picsure-location';
    const EXPERIMENT_STEP_3 = 'step-3-payment-new';
    const EXPERIMENT_PURCHASE_FLOW_BACS = 'purchase-flow-bacs';
    const EXPERIMENT_CPC_QUOTE_HOMEPAGE = 'cpc-quote-or-homepage';
    const EXPERIMENT_DOB = 'purchase-funnel-dob-dropdown';
    const EXPERIMENT_72_REPLACEMENT = 'seventytwo-hours';
    const EXPERIMENT_MONEY_LANDING = 'money-landing';
    const EXPERIMENT_PICSURE_SECTION = 'picsure-redesign';
    const EXPERIMENT_EBAY_LANDING = 'ebay-landing';
    const EXPERIMENT_EBAY_LANDING_1 = 'ebay-landing-1';
    const EXPERIMENT_EBAY_LANDING_2 = 'ebay-landing-2';
    const EXPERIMENT_NEW_WELCOME_MODAL = 'new-welcome-modal';
    // New Exp Competitor Landing
    const EXPERIMENT_COMPETITOR_LANDING = 'competitor-landing';
    const EXPERIMENT_TRUSTPILOT_REVIEW = 'trustpilot';
    // New Test Single Mem Option
    const EXPERIMENT_MEMORY_OPTIONS = 'single-progressive-dropdown';
    // New Test Money Back Guarantee
    const EXPERIMENT_MONEY_BACK_GUARANTEE = 'money-back-guarantee';
    // Exp 1
    const EXPERIMENT_HOMEPAGE_NEW_COPY = 'homepage-new-copy';
    // Exp 2

    // Exp 3

    // Exp 4

    const ALTERNATIVES_SHARE_MESSAGE_SIMPLE = 'simple';
    const ALTERNATIVES_APP_SHARE_METHOD_NATIVE = 'native';
    const ALTERNATIVES_APP_SHARE_METHOD_API = 'api';
    const ALTERNATIVES_APP_PICSURE_REQUEST_LOCATION = 'request-location';
    const ALTERNATIVES_APP_PICSURE_NO_LOCATION = 'no-location';

    const KPI_RECEIVE_DETAILS = 'receive-details';
    const KPI_QUOTE = 'quote';
    const KPI_POLICY_PURCHASE = 'policy-purchase';

    const EXPIRED_EXPERIMENT_SHARE_MESSAGE = 'share-message';

    public static $unauthExperiments = [
        self::EXPERIMENT_HOMEPAGE_AA_V2,
        self::EXPERIMENT_STEP_3,
        self::EXPERIMENT_PURCHASE_FLOW_BACS,
        self::EXPERIMENT_CPC_QUOTE_HOMEPAGE,
        self::EXPERIMENT_DOB,
        self::EXPERIMENT_72_REPLACEMENT,
        self::EXPERIMENT_MONEY_LANDING,
        self::EXPERIMENT_PICSURE_SECTION,
        self::EXPERIMENT_EBAY_LANDING,
        self::EXPERIMENT_EBAY_LANDING_1,
        self::EXPERIMENT_EBAY_LANDING_2,
        // New Exp Competitor Landing
        self::EXPERIMENT_COMPETITOR_LANDING,
        self::EXPERIMENT_TRUSTPILOT_REVIEW,
        // New Test Single Mem Option
        self::EXPERIMENT_MEMORY_OPTIONS,
        // New Test Money Back Guarantee
         self::EXPERIMENT_MONEY_BACK_GUARANTEE,
        // Exp 1
        self::EXPERIMENT_HOMEPAGE_NEW_COPY,
        // Exp 2

        // Exp 3

        // Exp 4
    ];

    public static $authExperiments = [
        self::EXPERIMENT_APP_SHARE_METHOD,
        self::EXPERIMENT_APP_PICSURE_LOCATION,
    ];

    public static $appExperiments = [
        self::EXPERIMENT_APP_SHARE_METHOD => [
            self::ALTERNATIVES_APP_SHARE_METHOD_NATIVE,
            self::ALTERNATIVES_APP_SHARE_METHOD_API,
        ],
        self::EXPERIMENT_APP_PICSURE_LOCATION => [
            self::ALTERNATIVES_APP_PICSURE_NO_LOCATION,
            self::ALTERNATIVES_APP_PICSURE_REQUEST_LOCATION,
        ],
    ];

    /**
     * For cases where there is only one conversion point (purchase)
     * @var array
     */
    public static $purchaseConversionSimple = [
        self::EXPERIMENT_CPC_QUOTE_HOMEPAGE,
        self::EXPERIMENT_STEP_3,
        self::EXPERIMENT_PURCHASE_FLOW_BACS,
        self::EXPERIMENT_72_REPLACEMENT,
        self::EXPERIMENT_MONEY_LANDING,
        self::EXPERIMENT_PICSURE_SECTION,
        self::EXPERIMENT_EBAY_LANDING,
        self::EXPERIMENT_EBAY_LANDING_1,
        self::EXPERIMENT_EBAY_LANDING_2,
        // New Exp Competitor Landing
        self::EXPERIMENT_COMPETITOR_LANDING,
        // Exp 1
        self::EXPERIMENT_HOMEPAGE_NEW_COPY,
        // Exp 2

        // Exp 3

        // Exp 4
    ];

    /**
     * For cases where there are multiple conversion points (e.g. convert on progress and later convert on purchase)
     * @var array
     */
    public static $purchaseConversionKpi = [
        self::EXPERIMENT_HOMEPAGE_AA_V2,
        self::EXPERIMENT_DOB,
        self::EXPERIMENT_TRUSTPILOT_REVIEW,
        // New Test Single Mem Option
        self::EXPERIMENT_MEMORY_OPTIONS,
        // New Test Money Back Guarantee
        self::EXPERIMENT_MONEY_BACK_GUARANTEE,
        // Exp 1

        // Exp 2

        // Exp 3

        // Exp 4
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

    public function participate(
        $experiment,
        $alternatives,
        $logMixpanel = self::LOG_MIXPANEL_NONE,
        $trafficFraction = 1,
        $clientId = null
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
            if (in_array($experiment, [
                self::EXPERIMENT_APP_SHARE_METHOD,
            ])) {
                $this->mixpanel->queuePersonProperties([sprintf('Sixpack: %s', $experiment) => $result], true);
            } elseif (in_array($experiment, self::$authExperiments)) {
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
