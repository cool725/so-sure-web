<?php

namespace App\Controller\Admin\Employee;

use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Service\ReportingService;
use DateInterval;
use DateTime;
use DateTimeZone;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Security("has_role('ROLE_EMPLOYEE')")
 *
 * optional: ?start=yyyy-mm-dd & ?end=yyyy-mm-dd (default: today to 7 days ago, midnight to midnight)
 * These are made by the form.
 */
class ReportController extends AbstractController
{
    use DateTrait;

    /** @var ReportingService */
    private $reporting;

    public function __construct(ReportingService $reporting)
    {
        $this->reporting = $reporting;
    }

    /** Collects the data needed for all three reports and returns it in an
     * associative array.
     * @param string $period is the period for the claims report
     * @return array of the three sub reports.
     */
    private function buildReport($period)
    {
        $report = ['periods' => array_keys(ReportingService::REPORT_PERIODS)];

        // Get the start and end dates for the given period.
        try {
            list($start, $end) = ReportingService::getLastPeriod($period);
        } catch (IllegalArgumentException $e) {
            $report['error'] = "Invalid URL, period {$period} does not exist.";
            $report['period'] = ReportingService::REPORT_PERIODS_DEFAULT;
            return $report;
        }

        $report['claims'] = $this->reporting->report($start, $end, false);
        $report['connections'] = $this->reporting->connectionReport();
        $report['scheduledPayments'] = $this->reporting->getScheduledPayments();
        $report['period'] = $period;
        return $report;
    }

    /**
     * @Route("/admin/reports",        name="admin_reports")
     */
    public function claimsReportAction(Request $request)
    {
        $period = $request->get('period');
        $report = $this->buildReport(isset($period) ? $period : ReportingService::REPORT_PERIODS_DEFAULT);
        return $this->render('AppBundle:AdminEmployee:adminReports.html.twig', $report);
    }
}
