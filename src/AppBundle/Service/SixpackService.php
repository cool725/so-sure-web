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

    //const EXPERIMENT_HOMEPAGE_AA = 'homepage-aa';
    const EXPERIMENT_LANDING_HOME = 'landing-or-home';
    const EXPERIMENT_CPC_QUOTE_MANUFACTURER = 'cpc-quote-or-manufacturer';
    const EXPERIMENT_SHARE_MESSAGE = 'share-message';
    const EXPERIMENT_HOMEPAGE_PHONE_IMAGE = 'homepage-phone-image';

    const ALTERNATIVES_SHARE_MESSAGE_SIMPLE = 'simple';
    const ALTERNATIVES_SHARE_MESSAGE_ORIGINAL = 'original';

    // Completed test - SW-45
    // const EXPERIMENT_QUOTE_CALC_LOWER = 'quote-calc-lower';

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
        $logMixpanel = false,
        $trafficFraction = 1,
        $clientId = null
    ) {
        // default to first option
        $result = $alternatives[0];
        try {
            if (!$clientId) {
                $clientId = $this->requestService->getUser() ?
                    $this->requestService->getUser()->getId() :
                    $this->requestService->getTrackingId();
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

        if ($logMixpanel) {
            $this->mixpanel->queuePersonProperties([sprintf('Sixpack: %s', $experiment) => $result], false);
        }

        return $result;
    }

    public function convert($experiment, $expectParticipating = false)
    {
        $unauth = $this->convertByClientId($this->requestService->getTrackingId(), $experiment);
        $auth = null;
        if ($user = $this->requestService->getUser()) {
            $auth = $this->convertByClientId($user->getId(), $experiment);
        }

        if (!$unauth && !$auth && $expectParticipating) {
            if ($user) {
                $this->logger->warning(sprintf(
                    'Expected participation in experiment %s for user %s',
                    $experiment,
                    $user->getId()
                ));
            } else {
                $this->logger->warning(sprintf(
                    'Expected participation in experiment %s for anon user',
                    $experiment
                ));
            }
        }
    }

    public function convertByClientId($clientId, $experiment)
    {
        try {
            $data = [
                'experiment' => $experiment,
                'client_id' => $clientId
            ];
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
            if (stripos($data['message'], 'not participating') !== false) {
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
        if ($experiment == self::EXPERIMENT_SHARE_MESSAGE) {
            // Expected [0] => 'share link', [1] => 'share code'
            if (count($data) != 2) {
                return null;
            }

            if ($alternative == self::ALTERNATIVES_SHARE_MESSAGE_SIMPLE) {
                return sprintf("Join me on so-sure, really cheap and pretty clever %s", $data[0]);
            } elseif ($alternative == self::ALTERNATIVES_SHARE_MESSAGE_ORIGINAL) {
                // @codingStandardsIgnoreStart
                return sprintf(
                    "Hey, I've just joined so-sure – better insurance that is up to 80%% cheaper if you and your friends don't claim. Finally, makes phone insurance worthwhile! You're careful, connect with me! Download here: %s. Add my code after you pay: %s",
                    $data[0],
                    $data[1]
                );
                // @codingStandardsIgnoreEnd
            }
        }

        return null;
    }
}
