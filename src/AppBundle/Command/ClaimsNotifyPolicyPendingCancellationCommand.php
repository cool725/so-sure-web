<?php

namespace AppBundle\Command;

use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\DaviesClaim;

class ClaimsNotifyPolicyPendingCancellationCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:claims:notify-pending-cancellation')
            ->setDescription('Notify claims handlers of policies w/open claims to be cancelled soon')
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
        /** @var PolicyService $policyService */
        $policyService = $this->getContainer()->get('app.policy');
        $count = $policyService->notifyPendingCancellations($prefix, $days);
        $output->writeln(sprintf('%d policies with open claims will be cancelled. Email report sent.', $count));
    }
}
