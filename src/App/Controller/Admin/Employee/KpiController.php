<?php

namespace App\Controller\Admin\Employee;

use App\Admin\Reports\KpiInterface;
use AppBundle\Document\DateTrait;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class KpiController extends AbstractController
{
    use DateTrait;

    /** @var KpiInterface */
    private $kpiReport;
    /** @var bool Flag to be able to empty the cached data, instead of use it. */
    private $clearCache = false;

    public function __construct(KpiInterface $kpiReport)
    {
        $this->kpiReport = $kpiReport;
    }

    /**
     * @Route("/kpi", name="admin_kpi")
     * @Route("/kpi/{now}", name="admin_kpi_date")
     * @Template("AppBundle::AdminEmployee/kpi.html.twig")
     *
     * If the URL has ?clear-cache=1 appended, any periods searched for will have the data removed from the cache
     */
    public function kpiAction(Request $request, $now = null): array
    {
        if ($request->get('clear-cache')) {
            $this->clearCache = true;
        }

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
            $startOfDay = $this->startOfDay(clone $date);
            $endOfDay = $this->endOfDay($end);

            if ($this->clearCache) {
                $this->kpiReport->clearCacheForPeriod($startOfDay, $endOfDay);
            }

            $week = $this->kpiReport->reportForPeriod($startOfDay, $endOfDay);

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
}
