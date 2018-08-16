<?php

namespace App\Admin\Reports;

use DateTime;
use Predis\Client;

class KpiCached implements KpiInterface, KpiCacheInterface
{
    /** @var KpiInterface inner, fetching service*/
    private $kpi;
    /** @var Client */
    private $redis;

    public function __construct(KpiInterface $kpi, Client $redis)
    {
        $this->kpi = $kpi;
        $this->redis = $redis;
    }

    public function reportForPeriod(DateTime $startOfDay, DateTime $endOfDay): array
    {
        $cacheKey = $this->keyForPeriod($startOfDay, $endOfDay);

        if ($value = $this->redis->get($cacheKey)) {
            return unserialize($value);
        }

        // get from cache, else fetch, and fill cache
        $value =  $this->kpi->reportForPeriod($startOfDay, $endOfDay);

        $this->redis->set($cacheKey, serialize($value));

        $tonight = strtotime('00:01 tomorrow');
        $this->redis->expire($cacheKey, $tonight);

        return $value;
    }

    public function clearCacheForPeriod(DateTime $startOfDay, DateTime $endOfDay)
    {
        $cacheKey = $this->keyForPeriod($startOfDay, $endOfDay);

        $this->redis->del([$cacheKey]);
    }

    private function keyForPeriod(DateTime $start, DateTime $end): string
    {
        return 'KpiCached:'.$start->format('Ymd.H').$end->format('Ymd.H');
    }
}
