<?php

namespace App\Admin\Reports;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Stats;
use AppBundle\Service\ReportingService;
use DateInterval;
use DateTime;
use Doctrine\ODM\MongoDB\DocumentManager;

class Kpi implements KpiInterface
{
    use DateTrait;

    /** @var ReportingService */
    private $reporting;
    private $policyRepo;
    private $statsRepo;

    public function __construct(DocumentManager $dm, ReportingService $reportingService)
    {
        $this->reporting = $reportingService;

        $this->statsRepo = $dm->getRepository(Stats::class);
        $this->policyRepo = $dm->getRepository(PhonePolicy::class);
    }

    public function collectWeekRanges(DateTime $now, int $numWeeks): array
    {
        $weeksRanges = [];

        $date = $this->showFromDate($now, $numWeeks);

        while ($date < $now) {
            $weeksRanges[] = $this->getWeekPeriod($date);
            $date = $date->add(new \DateInterval('P7D'));
        }

        return $weeksRanges;
    }

    public function reportForPeriod(array $dateRange): array
    {
        list($startOfDay, $endOfDay) = $dateRange;

        $week = [
            'start_date' => clone $startOfDay,
            'end_date' => $endOfDay,
            'end_date_disp' => (clone $endOfDay)->sub(new DateInterval('PT1S')),
        ];

        $week['period'] = $this->reporting->report($startOfDay, $endOfDay, true);
        // $totalStart = clone $end;
        // $totalStart = $totalStart->sub(new \DateInterval('P1Y'));
        $week['total'] = $this->reporting->report(new DateTime(SoSure::POLICY_START), $endOfDay, true);
        $week['sumPolicies'] = $this->reporting->sumTotalPoliciesPerWeek($endOfDay);

        $approved = $week['total']['approvedClaims'][Claim::STATUS_APPROVED]
            + $week['total']['approvedClaims'][Claim::STATUS_SETTLED];

        $week['freq-claims'] = 'N/A';
        if ($week['sumPolicies'] != 0) {
            $week['freq-claims'] = ((52 * $approved) / $week['sumPolicies']);
        }
        $week['total-policies'] = $this->policyRepo->countAllActivePolicies($endOfDay);

        /** @var Stats[] $stats */
        $stats = $this->statsRepo->getStatsByRange($startOfDay, $endOfDay);
        $week = array_merge($week, Stats::sum($stats));

        return $week;
    }

    /**
     * @codingStandardsIgnoreStart
     */
    public function getReportsByWeekRanges(array $weekRanges, bool $clearCache = false): array
    {
        // @codingStandardsIgnoreEnd

        $weeks = [];
        $count = 1;

        foreach ($weekRanges as $x => $range) {
            $week = $this->reportForPeriod($range);

            $week['count'] = $count;
            $weeks[] = $week;
            $count++;
        }

        return $weeks;
    }

    private function showFromDate($now, $numWeeks): DateTime
    {
        $date = new DateTime('2016-09-12 00:00');
        // TODO: Be smarter with start date, but this at least drops number of queries down significantly
        while ($date < $now) {
            // $end = clone $date;
            // $end->add(new \DateInterval('P6D'));
            // $end = $this->endOfDay($end);
            $date = $date->add(new \DateInterval('P7D'));
        }
        $date = $date->sub(new \DateInterval(sprintf('P%dD', $numWeeks * 7)));

        return $date;
    }

    private function getWeekPeriod($date): array
    {
        $end = clone $date;
        $end->add(new \DateInterval('P6D'));
        $startOfDay = $this->startOfDay(clone $date);
        $endOfDay = $this->endOfDay($end);

        return array($startOfDay, $endOfDay);
    }
}
