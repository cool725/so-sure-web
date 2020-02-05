<?php

namespace AppBundle\Command;

use AppBundle\Classes\Salva;
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
        $this->salva();
        $output->writeln('Finished');
    }

    private function salva($date = null, $production = true)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $lastMonth = clone $date;
        $lastMonth = $lastMonth->sub(new \DateInterval('P1M'));
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $lastMonth->format('Y'), $lastMonth->format('m'))
        );

        $paymentTotals = $this->reportingService->getAllPaymentTotals($date, Salva::NAME);
        $activePolicies = $this->reportingService->getActivePoliciesCount($date, Salva::NAME);
        $activePoliciesWithDiscount = $this->reportingService->getActivePoliciesWithPolicyDiscountCount(
            $date,
            Salva::NAME
        );
        $rewardPotLiability = $this->reportingService->getRewardPotLiability($date, Salva::NAME);
        $rewardPromoPotLiability = $this->reportingService->getRewardPotLiability($date, Salva::NAME, true);
        $rewardPotLiabilitySalva = $rewardPotLiability - $rewardPromoPotLiability;
        if (isset($paymentTotals['all']['avgPayment'])) {
            $this->statsService->set(Stats::ACCOUNTS_AVG_PAYMENTS, $date, $paymentTotals['all']['avgPayment']);
        } else {
            $this->statsService->set(Stats::ACCOUNTS_AVG_PAYMENTS, $date, null);
        }
        $this->statsService->set(Stats::ACCOUNTS_ACTIVE_POLICIES, $date, $activePolicies);
        $this->statsService->set(Stats::ACCOUNTS_ACTIVE_POLICIES_WITH_DISCOUNTS, $date, $activePoliciesWithDiscount);
        $this->statsService->set(Stats::ACCOUNTS_REWARD_POT_LIABILITY_SALVA, $date, $rewardPotLiabilitySalva);
        $this->statsService->set(Stats::ACCOUNTS_REWARD_POT_LIABILITY_SOSURE, $date, $rewardPromoPotLiability);
        if (isset($paymentTotals['totalRunRate'])) {
            $this->statsService->set(Stats::ACCOUNTS_ANNUAL_RUN_RATE, $date, $paymentTotals['totalRunRate']);
        } else {
            $this->statsService->set(Stats::ACCOUNTS_ANNUAL_RUN_RATE, $date, null);
        }

    }

    private function kpiPicsure($date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
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
            $date = \DateTime::createFromFormat('U', time());
        }

        $data = $this->reportingService->getCancelledAndPaymentOwed();

        $this->statsService->set(Stats::KPI_CANCELLED_AND_PAYMENT_OWED, $date, $data['cancelledAndPaymentOwed']);
    }
}
