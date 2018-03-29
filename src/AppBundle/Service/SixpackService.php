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

    //const EXPERIMENT_HOMEPAGE_AA = 'homepage-aa';
    //const EXPERIMENT_QUOTE_CALC_LOWER = 'quote-calc-lower';
    //const EXPERIMENT_LANDING_HOME = 'landing-or-home';
    //const EXPERIMENT_CPC_QUOTE_MANUFACTURER = 'cpc-quote-or-manufacturer';
    //const EXPERIMENT_HOMEPAGE_PHONE_IMAGE = 'homepage-phone-image';
    // const EXPERIMENT_QUOTE_SLIDER = 'quote-slider';
    //const EXPERIMENT_PYG_HOME= 'pyg-or-home';
    //const EXPERIMENT_QUOTE_SIMPLE_COMPLEX_SPLIT = 'quote-simple-complex-split';
    //const EXPERIMENT_QUOTE_SIMPLE_SPLIT = 'quote-simple-split';
    //const EXPERIMENT_CPC_MANUFACTURER_HOME = 'cpc-manufacturer-or-home';
    //const EXPERIMENT_CPC_MANUFACTURER_WITH_HOME = 'cpc-manufacturer-with-home';
    //const EXPERIMENT_POSTCODE = 'postcode';
    //const EXPERIMENT_HOMEPAGE_V1_V2 = 'homepage-v1-v2';
    //const EXPERIMENT_HOMEPAGE_V1_V2OLD_V2NEW = 'homepage-v1-v2old-v2new';
    //const EXPERIMENT_FUNNEL_V1_V2 = 'funnel-v1-v2';
    //const EXPERIMENT_HOMEPAGE_STICKYSEARCH_PICSURE = 'homepage-v2-sticksearch-picsure';
    //const EXPERIMENT_HOMEPAGE_STICKYSEARCH_SHUFFLE = 'homepage-v2-sticksearch-shuffle';
    //const EXPERIMENT_QUOTE_SECTIONS = 'quote-sections';
    //const EXPERIMENT_POLICY_PDF_DOWNLOAD = 'policy-pdf-download';
    //const EXPERIMENT_CANCELLATION = 'cancellation';
    //const EXPERIMENT_NEW_QUOTE_DESIGN = 'new-quote-design';
    //const EXPERIMENT_CPC_MANUFACTURER_OLD_NEW = 'cpc-manufacturer-old-new';

    const EXPERIMENT_HOMEPAGE_AA_V2 = 'homepage-aa-v2';
    const EXPERIMENT_APP_SHARE_METHOD = 'app-share-method';
    const EXPERIMENT_SAVE_QUOTE_24HOURS = 'save-quote-24-hours';
    const EXPERIMENT_USER_WELCOME_MODAL = 'welcome-modal';
    const EXPERIMENT_QUOTE_INTERCOM_PURCHASE = 'quote-intercom-purchase';
    const EXPERIMENT_MONEY_UNBOUNCE = 'money-unbounce';

    const ALTERNATIVES_SHARE_MESSAGE_SIMPLE = 'simple';

    const ALTERNATIVES_APP_SHARE_METHOD_NATIVE = 'native';
    const ALTERNATIVES_APP_SHARE_METHOD_API = 'api';

    const KPI_RECEIVE_DETAILS = 'receive-details';
    const KPI_QUOTE = 'quote';
    const KPI_POLICY_PURCHASE = 'policy-purchase';

    const EXPIRED_EXPERIMENT_SHARE_MESSAGE = 'share-message';

    public static $unauthExperiments = [
        self::EXPERIMENT_HOMEPAGE_AA_V2,
        self::EXPERIMENT_SAVE_QUOTE_24HOURS,
        self::EXPERIMENT_QUOTE_INTERCOM_PURCHASE,
        self::EXPERIMENT_MONEY_UNBOUNCE,
    ];

    public static $authExperiments = [
        self::EXPERIMENT_APP_SHARE_METHOD,
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
                    $clientId = $this->requestService->getUser()->getId();
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
        $this->mixpanel->queueTrack(
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
            $converted = $this->convertByClientId($this->requestService->getUser()->getId(), $experiment, $kpi);
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
        if (!$clientId || strlen($clientId) == 0) {
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
            $body = (string) $res->getBody();
            $data = json_decode($body, true);
            // {"status": "failed", "message": "this client was not participating"}
            // There appears to be an error with sixpack in use that is returning experiment does not exist
            // rather than this client was not participating
            // {"status": "failed", "message": "experiment does not exist"}
            if (stripos($data['message'], 'not participating') !== false ||
                stripos($data['message'], 'experiment does not exist') !== false) {
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
