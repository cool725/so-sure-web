<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Stats;
use AppBundle\Document\DateTrait;
use AppBundle\Service\StatsService;
use GuzzleHttp\Client;

class AppAnnieService
{
    use DateTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $apiKey;

    /** @var StatsService */
    protected $stats;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $apiKey
     * @param StatsService    $stats
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $apiKey,
        $stats
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->stats = $stats;
    }

    public function run(\DateTime $start, \DateTime $end = null, $save = true, $ignoreZero = false)
    {
        $start = $this->startOfDay($start);
        if (!$end) {
            $end = $start;
        }
        $apple = $this->queryApple($start, $end, $ignoreZero);
        $google = $this->queryGoogle($start, $end, $ignoreZero);
        if ($save) {
            if ($start != $end) {
                throw new \Exception('If using save, must be single day');
            }

            $this->stats->set(Stats::INSTALL_APPLE, $start, $apple['downloads']);
            $this->stats->set(Stats::INSTALL_GOOGLE, $start, $google['downloads']);
        }

        return ['apple' => $apple, 'google' => $google];
    }

    public function queryGoogle(\DateTime $start, \DateTime $end, $ignoreZero = false)
    {
        return $this->query('393777', '20600005476183', $start, $end, $ignoreZero);
    }
    
    public function queryApple(\DateTime $start, \DateTime $end, $ignoreZero = false)
    {
        return $this->query('393774', '1094307449', $start, $end, $ignoreZero);
    }

    public function query($accountId, $productId, \DateTime $start, \DateTime $end, $ignoreZero = false)
    {
        $url = sprintf(
            'https://api.appannie.com/v1.2/accounts/%s/products/%s/sales?start_date=%s&end_date=%s',
            $accountId,
            $productId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );
        $client = new Client();
        $res = $client->request('GET', $url, ['headers' => ['Authorization' => sprintf('bearer %s', $this->apiKey)]]);

        $body = (string) $res->getBody();
        $this->logger->info(sprintf('AppAnnie response: %s', $body));

        $data = json_decode($body, true);
        $salesList = $data['sales_list'];
        if (count($salesList) == 0 && !$ignoreZero) {
            throw new \Exception(sprintf(
                'Data not present for %s to %s',
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            ));
        }

        return [
            'downloads' => isset($salesList[0]) ? $salesList[0]['units']['product']['downloads'] : null,
            'results' => $data
        ];
    }
}
