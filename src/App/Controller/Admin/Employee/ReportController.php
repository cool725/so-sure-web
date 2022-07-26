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

    /**
     * @Route("/admin/reports",               name="admin_reports")
     * @Route("/admin/reports/claims",        name="admin_reports_claims")
     */
    public function claimsReportAction(Request $request)
    {
        $period = $request->get("period");
        $period = isset($period) ? $period : ReportingService::REPORT_PERIODS_DEFAULT;
        $report = ["period" => $period];
        $report["periods"] = ReportingService::getPeriodList();
        // Get the start and end dates for the given period.
        try {
            list($start, $end, $month) = ReportingService::getLastPeriod($period);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash("error", "Invalid URL, {$period} is not a valid reporting period.");
            list($start, $end, $month) = ReportingService::getLastPeriod(ReportingService::REPORT_PERIODS_DEFAULT);
        }
        $report["claims"] = $this->reporting->report($start, $end, false);
        // Format the reporting date depending on it's type.
        if ($month) {
            $report["month"] = $start->format("F Y");
        } else {
            $report["start"] = $start->format("d/m/Y");
            $report["end"] = $end->format("d/m/Y");
        }
        return $this->render("AppBundle:AdminEmployee:adminReports.html.twig", $report);
    }

    /**
     * @Route("/admin/reports/connections", name="admin_reports_connections")
     */
    public function connectionsReportAction()
    {
        $report["connections"] = $this->reporting->connectionReport();
        return $this->render("AppBundle:AdminEmployee:adminReports.html.twig", $report);
    }

    /**
     * @Route("/admin/reports/scheduled", name="admin_reports_scheduled")
     */
    public function scheduledReportAction()
    {
        $report["scheduledPayments"] = $this->reporting->getScheduledPayments();
        return $this->render("AppBundle:AdminEmployee:adminReports.html.twig", $report);
    }
}
