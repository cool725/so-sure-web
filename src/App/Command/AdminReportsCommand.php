<?php

namespace App\Command;

use App\Admin\Reports\KpiCached;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AdminReportsCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:admin:reports';
    protected static $defaultName = self::SERVICE_NAME;

    /**
     * @var KpiCached
     */
    private $kpiReport;

    public function __construct(KpiCached $kpiReport)
    {
        parent::__construct();
        $this->kpiReport = $kpiReport;
    }

    protected function configure()
    {
        $this->setDescription('Pre-generate all cacheable reports.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cacheKpiReport();
    }

    private function cacheKpiReport()
    {
        $weekRanges = $this->kpiReport->collectWeekRanges(new \DateTime('now'), 4);
        // throw away the result.
        $this->kpiReport->getReportsByWeekRanges($weekRanges, true);
    }
}
