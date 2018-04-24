<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\Stats;

class StatsCommand extends ContainerAwareCommand
{
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

        $data = $this->getReporting()->getPicSureData();
        $this->getStats()->set(Stats::KPI_PICSURE_TOTAL_APPROVED_POLICIES, $date, $data['picsureApprovedTotal']);
        $this->getStats()->set(Stats::KPI_PICSURE_TOTAL_REJECTED_POLICIES, $date, $data['picsureRejectedTotal']);
        $this->getStats()->set(Stats::KPI_PICSURE_TOTAL_UNSTARTED_POLICIES, $date, $data['picsureUnstartedTotal']);
        $this->getStats()->set(Stats::KPI_PICSURE_TOTAL_PREAPPROVED_POLICIES, $date, $data['picsurePreApprovedTotal']);
        $this->getStats()->set(Stats::KPI_PICSURE_TOTAL_INVALID_POLICIES, $date, $data['picsureInvalidTotal']);

        $this->getStats()->set(Stats::KPI_PICSURE_ACTIVE_APPROVED_POLICIES, $date, $data['picsureApprovedActive']);
        $this->getStats()->set(Stats::KPI_PICSURE_ACTIVE_REJECTED_POLICIES, $date, $data['picsureRejectedActive']);
        $this->getStats()->set(Stats::KPI_PICSURE_ACTIVE_UNSTARTED_POLICIES, $date, $data['picsureUnstartedActive']);
        $this->getStats()->set(
            Stats::KPI_PICSURE_ACTIVE_PREAPPROVED_POLICIES,
            $date,
            $data['picsurePreApprovedActive']
        );
        $this->getStats()->set(Stats::KPI_PICSURE_ACTIVE_INVALID_POLICIES, $date, $data['picsureInvalidActive']);
    }

    private function cancelledAndPaymentOwed($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $data = $this->getReporting()->getCancelledAndPaymentOwed();

        $this->getStats()->set(Stats::KPI_CANCELLED_AND_PAYMENT_OWED, $date, $data['cancelledAndPaymentOwed']);
    }

    private function getStats()
    {
        return $this->getContainer()->get('app.stats');
    }

    private function getReporting()
    {
        return $this->getContainer()->get('app.reporting');
    }
}
