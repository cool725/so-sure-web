<?php

namespace App\Command;

use App\Admin\Reports\KpiCached;
use AppBundle\Classes\SoSure;
use AppBundle\Classes\Salva;
use AppBundle\Classes\Helvetia;
use AppBundle\Document\DateTrait;
use AppBundle\Service\ReportingService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates and caches admin reports.
 */
class AdminReportsCommand extends ContainerAwareCommand
{
    use DateTrait;
    const SERVICE_NAME = 'sosure:admin:reports';
    protected static $defaultName = self::SERVICE_NAME;

    /** @var KpiCached */
    private $kpiReport;
    /** @var ReportingService */
    private $reporting;
    /** @var string */
    private $environment;

    /**
     * Puts the dependencies into the command.
     * @param KpiCached        $kpiReport   is the kpi report.
     * @param ReportingService $reporting   is the reporting service which generates the reports.
     * @param string           $environment is the environment that this command is running in.
     */
    public function __construct(KpiCached $kpiReport, ReportingService $reporting, $environment)
    {
        parent::__construct();
        $this->kpiReport = $kpiReport;
        $this->reporting = $reporting;
        $this->environment = $environment;
    }

    /**
     * @InheritDoc
     */
    protected function configure()
    {
        $this->setDescription('Pre-generate/run cacheable reports.')
            ->addOption('kpi', null, InputOption::VALUE_NONE, 'Run the \'kpi\' report')
            ->addOption('claims', null, InputOption::VALUE_NONE, 'Run the \'claims\' report')
            ->addOption('accounts', null, InputOption::VALUE_NONE, 'Run the \'accounts\' report')
            ->addOption('connections', null, InputOption::VALUE_NONE, 'Run the \'connections\' report')
            ->addOption('pnl', null, InputOption::VALUE_NONE, 'Run the quarterly P&L report')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Use with pnl to cache historic reports. Format yyyy/mm'
            )
            ->addOption('underwriter', null, InputOption::VALUE_REQUIRED, sprintf(
                'Use with accounts to select which underwriter\'s accounts to cache. \'%s\' or \'%s\'',
                Salva::NAME,
                Helvetia::NAME
            ));
    }

    /**
     * @InheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Validate options.
        $underwriter = $input->getOption('underwriter');
        $date = new \DateTime();
        if ($underwriter && $underwriter != Salva::NAME && $underwriter != Helvetia::NAME) {
            $output->writeln(sprintf(
                '<error>Underwriter option should be either \'%s\' or \'%s\'</error>',
                Salva::NAME,
                Helvetia::NAME
            ));
            return;
        }
        if ($input->getOption('date')) {
            $toUse = explode('/', $input->getOption('date'));
            if (mb_strlen($toUse[0]) === 4 && mb_strlen($toUse[1]) === 2) {
                $date->setDate((int) $toUse[0], (int) $toUse[1], 1);
            }
        }
        // Do the bits.
        if ($input->getOption('claims')) {
            $this->cacheClaimsMainReport();
        }
        if ($input->getOption('kpi')) {
            $this->cacheKpiReport();
        }
        if ($input->getOption('accounts')) {
            $this->cacheAccountsReport($output, $underwriter ?: Helvetia::NAME);
        }
        if ($input->getOption('connections')) {
            $this->cacheConnectionsReport();
        }
        if ($input->getOption('pnl')) {
            $this->cachePNLReport($date);
        }
    }

    /**
     * Generates and caches the kpi report.
     */
    private function cacheKpiReport()
    {
        $weekRanges = $this->kpiReport->collectWeekRanges(new \DateTime(), 4);
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
    }

    /**
     * Runs the connections report and caches it.
     */
    private function cacheConnectionsReport()
    {
        $this->reporting->connectionReport();
    }

    /**
     * Runs the accounts reports that need caching so that they get cached.
     */
    private function cacheAccountsReport(OutputInterface $output, $underwriter)
    {
        $date = $this->startOfMonth(new \DateTime());
        $lastMonth = $this->startOfPreviousMonth($date);
        $twoMonths = $this->startOfPreviousMonth($lastMonth);
        $threeMonths = $this->startOfPreviousMonth($twoMonths);
        $output->writeln(sprintf('Caching accounts for %s', $date->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($date, $underwriter, false);
        $output->writeln(sprintf('Caching accounts for %s', $lastMonth->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($lastMonth, $underwriter, false);
        $output->writeln(sprintf('Caching accounts for %s', $twoMonths->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($twoMonths, $underwriter, false);
        $output->writeln(sprintf('Caching accounts for %s', $threeMonths->format(\DateTime::ATOM)));
        $this->reporting->getAllPaymentTotals($threeMonths, $underwriter, false);
    }

    /**
     * Runs the pnl report and caches it.
     */
    private function cachePNLReport($date)
    {
        $this->reporting->getQuarterlyPL($date);
    }
}
