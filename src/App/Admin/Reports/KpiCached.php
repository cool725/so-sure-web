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

    public function collectWeekRanges(DateTime $now, int $numWeeks): array
    {
        return $this->kpi->collectWeekRanges($now, $numWeeks);
    }

    public function reportForPeriod(array $dateRange): array
    {
        list($startOfDay, $endOfDay) = $dateRange;

        $cacheKey = $this->keyForPeriod($startOfDay, $endOfDay);
        if ($this->redis->exists($cacheKey)) {
            $data = unserialize($this->redis->get($cacheKey));
            if ($data) {
                return $data;
            }
        }

        // get from cache, else fetch, and fill cache
        $value = $this->kpi->reportForPeriod($dateRange);

        $this->redis->set($cacheKey, serialize($value));
        $tonight = strtotime('00:01 tomorrow');
        $this->redis->expire($cacheKey, $tonight);

        return $value;
    }

    public function getReportsByWeekRanges(array $weekRanges, bool $clearCache = false): array
    {
        $weeks = [];
        $count = 1;

        foreach ($weekRanges as $dateRange) {
            if ($clearCache === true) {
                $this->clearCacheForPeriod($dateRange);
            }
            $week = $this->reportForPeriod($dateRange);

            $week['count'] = $count;
            $weeks[] = $week;
            $count++;
        }

        return $weeks;
    }

    private function clearCacheForPeriod(array $dateRange)
    {
        list($startOfDay, $endOfDay) = $dateRange;
        $cacheKey = $this->keyForPeriod($startOfDay, $endOfDay);

        $this->redis->del([$cacheKey]);
    }

    private function keyForPeriod(DateTime $start, DateTime $end): string
    {
        return 'KpiCached:'.$start->format('Ymd.H').'-'.$end->format('Ymd.H');
    }
}
