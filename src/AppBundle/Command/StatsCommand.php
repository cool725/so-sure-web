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
        $output->writeln('Finished');
    }

    private function kpiPicsure($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $data = $this->getReporting()->getPicSureData();

        $this->getStats()->set(Stats::KPI_PICSURE_APPROVED_POLICIES, $date, $data['picsureApproved']);
        $this->getStats()->set(Stats::KPI_PICSURE_REJECTED_POLICIES, $date, $data['picsureRejected']);
        $this->getStats()->set(Stats::KPI_PICSURE_UNSTARTED_POLICIES, $date, $data['picsureUnstarted']);
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
