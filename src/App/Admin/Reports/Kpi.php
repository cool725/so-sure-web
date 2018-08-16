<?php

namespace App\Admin\Reports;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Stats;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\StatsRepository;
use AppBundle\Service\ReportingService;
use DateInterval;
use DateTime;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

class Kpi implements KpiInterface
{
    /** @var ReportingService */
    private $reporting;
    /** @var PhonePolicyRepository|ObjectRepository */
    private $policyRepo;
    /** @var StatsRepository|ObjectRepository */
    private $statsRepo;

    public function __construct(DocumentManager $documentManager, ReportingService $reportingService)
    {
        $this->reporting = $reportingService;

        $this->statsRepo = $documentManager->getRepository(Stats::class);
        $this->policyRepo = $documentManager->getRepository(PhonePolicy::class);
    }

    public function reportForPeriod(DateTime $startOfDay, DateTime $endOfDay): array
    {
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
        foreach ($stats as $stat) {
            if (!isset($week[$stat->getName()])) {
                $week[$stat->getName()] = 0;
            }
            if (!$stat->isAbsolute()) {
                $week[$stat->getName()] += $stat->getValue();
            } else {
                $week[$stat->getName()] = $stat->getValue();
            }
        }

        foreach (Stats::$allStats as $stat) {
            if (!isset($week[$stat])) {
                $week[$stat] = '-';
            }
        }

        return $week;
    }
}
