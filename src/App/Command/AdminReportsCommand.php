<?php

namespace App\Command;

use App\Admin\Reports\KpiCached;
use AppBundle\Service\ReportingService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AdminReportsCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:admin:reports';
    protected static $defaultName = self::SERVICE_NAME;

    /**
     * @var KpiCached
     */
    private $kpiReport;
    /** @var ReportingService */
    private $reporting;

    public function __construct(KpiCached $kpiReport, ReportingService $reporting)
    {
        parent::__construct();
        $this->kpiReport = $kpiReport;
        $this->reporting = $reporting;
    }

    protected function configure()
    {
        $this->setDescription('Pre-generate/run cacheable reports.')
            ->addOption('kpi', null, InputOption::VALUE_NONE, "Run the 'kpi' report")
            ->addOption('claims', null, InputOption::VALUE_NONE, "Run the 'claims' report")
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
        $oldest = new \DateTime();
        foreach (ReportingService::REPORT_PERIODS as $period => $dates) {
            list($start, $end) = ReportingService::getLastPeriod($period);
            $this->reporting->report($start, $end, false, false);
            if ($start < $oldest) {
                $oldest = $start;
            }
        }
        $this->reporting->getCumulativePolicies($oldest, new \DateTime(), false);
    }
}
