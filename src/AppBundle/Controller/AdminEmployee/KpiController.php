<?php

namespace AppBundle\Controller\AdminEmployee;

use AppBundle\Classes\SoSure;
use AppBundle\Controller\BaseController;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Stats;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class KpiController extends BaseController implements ContainerAwareInterface
{
    use DateTrait;
    // use CurrencyTrait;
    // use ImeiTrait;
    use ContainerAwareTrait;

    /**
     * @Route("/kpi", name="admin_kpi")
     * @Route("/kpi/{now}", name="admin_kpi_date")
     * @Template("AppBundle::AdminEmployee/kpi.html.twig")
     */
    public function kpiAction($now = null)
    {
        // default 30s for prod is no longer enough
        // TODO: Refactor method to improve performance
        set_time_limit(60);
        $numWeeks = 4;

        if (!$now) {
            $now = new \DateTime();
        } else {
            $now = new \DateTime($now);
        }

        $weeks = $this->getReportsByWeek($now, $numWeeks);

        $adjustedWeeks = array_slice($weeks, 0 - $numWeeks);
        $reversedAdjustedWeeks = array_reverse($adjustedWeeks);
        /** @var \DateTime $prevPageDate */
        $prevPageDate = $reversedAdjustedWeeks[0]['end_date'];
        $prevPageDate->add(new \DateInterval('P'.($numWeeks).'W'));

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

    private function getReportsByWeek($now, $numWeeks): array
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(PhonePolicy::class);
        $statsRepo = $dm->getRepository(Stats::class);

        $date = $this->showFromDate($now, $numWeeks);

        $weeks = [];
        $count = 1;
        while ($date < $now) {
            $end = clone $date;
            $end->add(new \DateInterval('P6D'));
            $end = $this->endOfDay($end);
            $week = [
                'start_date' => clone $date,
                'end_date' => $end,
                'end_date_disp' => (clone $end)->sub(new \DateInterval('PT1S')),
                'count' => $count,
            ];

            $start = $this->startOfDay(clone $date);
            $date = $date->add(new \DateInterval('P7D'));

            $count++;
            $reporting = $this->get('app.reporting');
            $week['period'] = $reporting->report($start, $end, true);
            // $totalStart = clone $end;
            // $totalStart = $totalStart->sub(new \DateInterval('P1Y'));
            $week['total'] = $reporting->report(new \DateTime(SoSure::POLICY_START), $end, true);
            $week['sumPolicies'] = $reporting->sumTotalPoliciesPerWeek($end);

            $approved = $week['total']['approvedClaims'][Claim::STATUS_APPROVED]
                + $week['total']['approvedClaims'][Claim::STATUS_SETTLED];

            $week['freq-claims'] = 'N/A';
            if ($week['sumPolicies'] != 0) {
                $week['freq-claims'] = ((52 * $approved) / $week['sumPolicies']);
            }
            $week['total-policies'] = $policyRepo->countAllActivePolicies($date);

            /** @var Stats[] $stats */
            $stats = $statsRepo->getStatsByRange($start, $date);
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
            };

            $weeks[] = $week;
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
}
