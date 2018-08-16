<?php

namespace App\Controller\Admin\Employee;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Stats;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\StatsRepository;
use AppBundle\Service\ReportingService;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class KpiController extends AbstractController
{
    use DateTrait;

    /** @var ReportingService */
    private $reporting;
    /** @var PhonePolicyRepository|ObjectRepository  */
    private $policyRepo;
    /** @var StatsRepository|ObjectRepository  */
    private $statsRepo;
    /** @var DocumentManager */
    private $documentManager;

    public function __construct(DocumentManager $documentManager, ReportingService $reportingService)
    {
        $this->reporting = $reportingService;

        $this->statsRepo = $documentManager->getRepository(Stats::class);
        $this->policyRepo = $documentManager->getRepository(PhonePolicy::class);
    }

    /**
     * @Route("/kpi", name="admin_kpi")
     * @Route("/kpi/{now}", name="admin_kpi_date")
     * @Template("AppBundle::AdminEmployee/kpi.html.twig")
     */
    public function kpiAction($now = null): array
    {
        // default 30s for prod is no longer enough
        // TODO: Refactor method to improve performance
        set_time_limit(60);
        $numWeeks = 4;

        if (!$now) {
            $now = new \DateTimeImmutable();
        } else {
            $now = new \DateTimeImmutable($now);
        }

        $weeks = $this->getReportsByWeek($now, $numWeeks);

        $adjustedWeeks = array_slice($weeks, 0 - $numWeeks);
        $reversedAdjustedWeeks = array_reverse($adjustedWeeks);
        /** @var \DateTime $prevPageDate */
        $prevPageDate = $reversedAdjustedWeeks[0]['end_date'];
        $prevPageDate->add(new \DateInterval('P'.$numWeeks.'W'));

        return [
            'weeks' => $reversedAdjustedWeeks,
            'next_page' => $this->generateUrl('admin_kpi_date', [
                'now' => $adjustedWeeks[0]['start_date']->format('y-m-d')
            ]),
            'previous_page' => $this->generateUrl('admin_kpi_date', [
                'now' => $prevPageDate->format('y-m-d')
            ]),
            'now' => $now,
        ];
    }

    private function getReportsByWeek(\DateTimeImmutable $now, int $numWeeks): array
    {
        $date = $this->showFromDate($now, $numWeeks);

        $weeks = [];
        $count = 1;

        while ($date < $now) {
            $end = clone $date;
            $end->add(new \DateInterval('P6D'));

            $week = $this->reportForPeriod($this->startOfDay(clone $date), $this->endOfDay($end));

            $week['count'] = $count;
            $weeks[] = $week;

            $date = $date->add(new \DateInterval('P7D'));
            $count++;
        }

        return $weeks;
    }

    private function showFromDate($now, $numWeeks): \DateTime
    {
        $date = new \DateTime('2016-09-12 00:00');
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

    private function reportForPeriod(\DateTime $start, \DateTime $end): array
    {
        $week = [
            'start_date' => clone $start,
            'end_date' => $end,
            'end_date_disp' => (clone $end)->sub(new \DateInterval('PT1S')),
        ];

        $week['period'] = $this->reporting->report($start, $end, true);
        // $totalStart = clone $end;
        // $totalStart = $totalStart->sub(new \DateInterval('P1Y'));
        $week['total'] = $this->reporting->report(new \DateTime(SoSure::POLICY_START), $end, true);
        $week['sumPolicies'] = $this->reporting->sumTotalPoliciesPerWeek($end);

        $approved = $week['total']['approvedClaims'][Claim::STATUS_APPROVED] +
            $week['total']['approvedClaims'][Claim::STATUS_SETTLED];

        $week['freq-claims'] = 'N/A';
        if ($week['sumPolicies'] != 0) {
            $week['freq-claims'] = ((52 * $approved) / $week['sumPolicies']);
        }
        $week['total-policies'] = $this->policyRepo->countAllActivePolicies($end);

        /** @var Stats[] $stats */
        $stats = $this->statsRepo->getStatsByRange($start, $end);
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
