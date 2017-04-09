<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Stats;
use AppBundle\Document\DateTrait;
use AppBundle\Service\StatsService;
use GuzzleHttp\Client;

class SixpackService
{
    const TIMEOUT = 3;

    const EXPERIMENT_HOMEPAGE_AA = 'homepage-aa';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $url;

    /** @var RequestService */
    protected $requestService;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $url
     * @param RequestService  $requestService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $url,
        RequestService $requestService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->url = $url;
        $this->requestService = $requestService;
    }

    public function participate($experiment, $alternatives, $trafficFraction = 1)
    {
        try {
            $data = [
                'experiment' => $experiment,
                'client_id' => $this->requestService->getUser() ?
                    $this->requestService->getUser()->get() :
                    $this->requestService->getTrackingId(),
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
    
            // {"status": "ok", "alternative": {"name": "red"}, "experiment": {"name": "button_color"}, "client_id": "12345678-1234-5678-1234-567812345678"}
            $data = json_decode($body, true);
    
            return $data['alternative']['name'];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed exp %s', $experiment), ['exception' => $e]);
        }

        return $alternatives[0];
    }

    public function convert($experiment)
    {
        try {
            $data = [
                'experiment' => $experiment,
                'client_id' => $this->requestService->getUser() ?
                    $this->requestService->getUser()->get() :
                    $this->requestService->getTrackingId(),
            ];
            $query = http_build_query($data);
            $url = sprintf('%s/convert?%s', $this->url, $query);
            $client = new Client();
            $res = $client->request('GET', $url, ['connect_timeout' => self::TIMEOUT, 'timeout' => self::TIMEOUT]);
    
            $body = (string) $res->getBody();
            $this->logger->info(sprintf('Sixpack convert response: %s', $body));
    
            // {"status": "ok", "alternative": {"name": "red"}, "experiment": {"name": "button_color"}, "conversion": {"kpi": null, "value": null}, "client_id": "12345678-1234-5678-1234-567812345678"}
            $data = json_decode($body, true);
    
            return $data['alternative']['name'];
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed exp %s', $experiment), ['exception' => $e]);
        }

        return $alternatives[0];
    }
}
