<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Stats;
use AppBundle\Document\DateTrait;
use GuzzleHttp\Client;

class AppAnnieService
{
    use DateTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $apiKey;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $apiKey
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $apiKey
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
    }
    
    public function run(\DateTime $start, \DateTime $end = null, $save = true)
    {
        $start = $this->startOfDay($start);
        if (!$end) {
            $end = $start;
        }
        $apple = $this->queryApple($start, $end);
        $google = $this->queryGoogle($start, $end);
        if ($save) {
            if ($start != $end) {
                throw new \Exception('If using save, must be single day');
            }
            
            $repo = $this->dm->getRepository(Stats::class);

            $statApple = $repo->findOneBy(['name' => Stats::INSTALL_APPLE, 'date' => $start]);
            if (!$statApple) {
                $statApple = new Stats();
                $statApple->setDate($start);
                $statApple->setName(Stats::INSTALL_APPLE);
                $this->dm->persist($statApple);
            }
            $statApple->setValue($apple);

            $statGoogle = $repo->findOneBy(['name' => Stats::INSTALL_GOOGLE, 'date' => $start]);
            if (!$statGoogle) {
                $statGoogle = new Stats();
                $statGoogle->setDate($start);
                $statGoogle->setName(Stats::INSTALL_GOOGLE);
                $this->dm->persist($statGoogle);
            }
            $statGoogle->setValue($google);

            $this->dm->flush();
        }

        return ['apple' => $apple, 'google' => $google];
    }

    public function queryGoogle(\DateTime $start, \DateTime $end)
    {
        return $this->query('393777', '20600005476183', $start, $end);
    }
    
    public function queryApple(\DateTime $start, \DateTime $end)
    {
        return $this->query('393774', '1094307449', $start, $end);
    }

    public function query($accountId, $productId, \DateTime $start, \DateTime $end)
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
        if (count($salesList) == 0) {
            throw new \Exception(sprintf(
                'Data not present for %s to %s',
                $start->format('Y-m-d'),
                $end->format('Y-m-d')));
        }

        return $salesList[0]['units']['product']['downloads'];
    }
}
