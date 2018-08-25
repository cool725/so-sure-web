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
     * @Route("/admin/reports",        name="admin_reports")
     * @Route("/admin/reports/claims", name="admin_reports_claims")
     */
    public function claimsReportAction(Request $request)
    {
        list($start, $end) = $this->reporting->getLast7DaysPeriod($request->get('start'), $end = $request->get('end'));

        $report = $this->reporting->report($start, $end, false);    // 233 queries

        return $this->render('AppBundle:AdminEmployee:adminReports.html.twig', $report);
    }

    /**
     * @Route("/admin/reports/connections", name="admin_reports_connections")
     */
    public function connectionsReportAction()
    {
        $report['data'] = $this->reporting->connectionReport();

        return $this->render('AppBundle:AdminEmployee:adminReports.html.twig', $report);
    }

    /**
     * @Route("/admin/reports/scheduled", name="admin_reports_scheduled")
     */
    public function scheduledReportAction()
    {
        $report['data']['scheduledPayments'] = $this->reporting->getScheduledPayments();

        return $this->render('AppBundle:AdminEmployee:adminReports.html.twig', $report);
    }
}
