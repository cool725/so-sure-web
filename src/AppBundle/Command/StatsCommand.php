<?php

namespace AppBundle\Command;

use AppBundle\Service\ReportingService;
use AppBundle\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\Stats;

class StatsCommand extends ContainerAwareCommand
{
    /** @var ReportingService */
    protected $reportingService;

    /** @var StatsService */
    protected $statsService;

    public function __construct(ReportingService $reportingService, StatsService $statsService)
    {
        parent::__construct();
        $this->reportingService = $reportingService;
        $this->statsService = $statsService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:stats')
            ->setDescription('Record so-sure stats')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->kpiPicsure();
        $this->cancelledAndPaymentOwed();
        $output->writeln('Finished');
    }

    private function kpiPicsure($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $data = $this->reportingService->getPicSureData();
        $this->statsService->set(Stats::KPI_PICSURE_TOTAL_APPROVED_POLICIES, $date, $data['picsureApprovedTotal']);
        $this->statsService->set(Stats::KPI_PICSURE_TOTAL_REJECTED_POLICIES, $date, $data['picsureRejectedTotal']);
        $this->statsService->set(Stats::KPI_PICSURE_TOTAL_UNSTARTED_POLICIES, $date, $data['picsureUnstartedTotal']);
        $this->statsService->set(
            Stats::KPI_PICSURE_TOTAL_PREAPPROVED_POLICIES,
            $date,
            $data['picsurePreApprovedTotal']
        );
        $this->statsService->set(
            Stats::KPI_PICSURE_TOTAL_CLAIMS_APPROVED_POLICIES,
            $date,
            $data['picsureClaimsApprovedTotal']
        );
        $this->statsService->set(Stats::KPI_PICSURE_TOTAL_INVALID_POLICIES, $date, $data['picsureInvalidTotal']);

        $this->statsService->set(Stats::KPI_PICSURE_ACTIVE_APPROVED_POLICIES, $date, $data['picsureApprovedActive']);
        $this->statsService->set(Stats::KPI_PICSURE_ACTIVE_REJECTED_POLICIES, $date, $data['picsureRejectedActive']);
        $this->statsService->set(Stats::KPI_PICSURE_ACTIVE_UNSTARTED_POLICIES, $date, $data['picsureUnstartedActive']);
        $this->statsService->set(
            Stats::KPI_PICSURE_ACTIVE_PREAPPROVED_POLICIES,
            $date,
            $data['picsurePreApprovedActive']
        );
        $this->statsService->set(
            Stats::KPI_PICSURE_ACTIVE_CLAIMS_APPROVED_POLICIES,
            $date,
            $data['picsureClaimsApprovedActive']
        );
        $this->statsService->set(Stats::KPI_PICSURE_ACTIVE_INVALID_POLICIES, $date, $data['picsureInvalidActive']);
    }

    private function cancelledAndPaymentOwed($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $data = $this->reportingService->getCancelledAndPaymentOwed();

        $this->statsService->set(Stats::KPI_CANCELLED_AND_PAYMENT_OWED, $date, $data['cancelledAndPaymentOwed']);
    }
}
