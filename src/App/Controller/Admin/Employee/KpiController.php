<?php

namespace App\Controller\Admin\Employee;

use App\Admin\Reports\KpiInterface;
use AppBundle\Document\DateTrait;
use DateInterval;
use DateTime;
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
        /** @var bool $clearCache */
        $clearCache = false;
        if ($request->get('clear-cache') !== null) {
            $clearCache = true;
        }

        // default 30s for prod is no longer enough
        // TODO: Refactor method to improve performance
        set_time_limit(60);
        $numWeeks = 4;

        if ($now) {
            $now = new DateTime($now);
        } else {
            $now = new DateTime();
        }

        $weekRanges = $this->kpiReport->collectWeekRanges($now, $numWeeks);
        $weeks = $this->kpiReport->getReportsByWeekRanges($weekRanges, $clearCache);

        $adjustedWeeks = array_slice($weeks, 0 - $numWeeks);
        $reversedAdjustedWeeks = array_reverse($adjustedWeeks);
        /** @var DateTime $prevPageDate */
        $prevPageDate = $reversedAdjustedWeeks[0]['end_date'];
        $prevPageDate->add(new DateInterval('P'.$numWeeks.'W'));

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
}
