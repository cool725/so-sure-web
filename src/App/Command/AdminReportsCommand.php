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
        $this->setDescription('Pre-generate all cacheable reports.')
            ->addOption(
                'daily',
                null,
                InputOption::VALUE_NONE,
                "Run only the 'daily' reports"
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Running the 'frequent' report(s)", OutputInterface::VERBOSITY_VERBOSE);
        $this->cacheClaimsMainReport();

        if ($input->getOption('daily')) {
            $output->writeln("Running the 'daily' report(s)", OutputInterface::VERBOSITY_VERBOSE);
            $this->cacheKpiReport();
        }
    }

    private function cacheKpiReport()
    {
        $weekRanges = $this->kpiReport->collectWeekRanges(new \DateTime('now'), 4);
        // throw away the result.
        $this->kpiReport->getReportsByWeekRanges($weekRanges, true);
    }

    private function cacheClaimsMainReport()
    {
        list($start, $end) = $this->reporting->getLast7DaysPeriod(null, null);
        // throw away the result.
        $this->reporting->report($start, $end, false, $useCache = false);
    }
}
