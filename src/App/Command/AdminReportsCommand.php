<?php

namespace App\Command;

use App\Admin\Reports\KpiCached;
use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Service\ReportingService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AdminReportsCommand extends ContainerAwareCommand
{
    use DateTrait;
    const SERVICE_NAME = 'sosure:admin:reports';
    protected static $defaultName = self::SERVICE_NAME;

    /**
     * @var KpiCached
     */
    private $kpiReport;
    /** @var ReportingService */
    private $reporting;

    private $environment;

    public function __construct(KpiCached $kpiReport, ReportingService $reporting, $environment)
    {
        parent::__construct();
        $this->kpiReport = $kpiReport;
        $this->reporting = $reporting;
        $this->environment = $environment;
    }

    protected function configure()
    {
        $this->setDescription('Pre-generate/run cacheable reports.')
            ->addOption('kpi', null, InputOption::VALUE_NONE, "Run the 'kpi' report")
            ->addOption('claims', null, InputOption::VALUE_NONE, "Run the 'claims' report")
            ->addOption('accounts', null, InputOption::VALUE_NONE, "Run the 'accounts' report")
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('claims')) {
            $this->cacheClaimsMainReport();
        }

        if ($input->getOption('kpi')) {
            $this->cacheKpiReport();
        }

        if ($input->getOption('accounts')) {
            $this->cacheAccountsReport($output);
        }
    }

    private function cacheKpiReport()
    {
        $weekRanges = $this->kpiReport->collectWeekRanges(new \DateTime('now'), 4);
        // throw away the result.
        $this->kpiReport->getReportsByWeekRanges($weekRanges, true);
    }

    /**
     * Runs the claims reports that need caching so that they get cached.
     */
    private function cacheClaimsMainReport()
    {
        foreach (ReportingService::REPORT_PERIODS as $period => $dates) {
            list($start, $end) = ReportingService::getLastPeriod($period);
            $this->reporting->report($start, $end, false, false);
        }
        $this->reporting->getCumulativePolicies(new \DateTime(SoSure::POLICY_START), new \DateTime(), false);
    }

    /**
     * Runs the accounts reports that need caching so that they get cached.
     */
    private function cacheAccountsReport(OutputInterface $output)
    {
        $date = new \DateTime();
        $date = $this->startOfMonth($date);
        $lastMonth = $this->startOfPreviousMonth($date);
        $twoMonths = $this->startOfPreviousMonth($lastMonth);
        $threeMonths = $this->startOfPreviousMonth($twoMonths);

        $isProd = $this->environment == 'prod';
        $output->writeln(sprintf('Caching accounts for %s', $date->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($isProd, $date, false);
        $output->writeln(sprintf('Caching accounts for %s', $lastMonth->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($isProd, $lastMonth, false);
        $output->writeln(sprintf('Caching accounts for %s', $twoMonths->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($isProd, $twoMonths, false);
        $output->writeln(sprintf('Caching accounts for %s', $threeMonths->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($isProd, $threeMonths, false);
    }
}
