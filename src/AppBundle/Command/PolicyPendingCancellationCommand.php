<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\DaviesClaim;

class PolicyPendingCancellationCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:pending-cancellation')
            ->setDescription('Notify davies of policies that will be cancelled soon with open claims')
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days before cancellation to notify (default: 5)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $prefix = $input->getOption('prefix');
        $days = $input->getOption('days');
        $policyService = $this->getContainer()->get('app.policy');
        $count = $policyService->notifyPendingCancellations($prefix, $days);
        $output->writeln(sprintf('%d policies with open claims will be cancelled. Email report sent.', $count));
    }
}
