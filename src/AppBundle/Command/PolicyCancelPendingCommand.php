<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\Payment;
use AppBundle\Document\User;

class PolicyCancelPendingCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:cancel-pending')
            ->setDescription('Cancel any policies that are pending cancellation')
            ->addOption(
                'daily',
                null,
                InputOption::VALUE_NONE,
                'Run a daily reporting on pending cancellations'
            )
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isDaily = true === $input->getOption('daily');
        $prefix = $input->getOption('prefix');

        $policyService = $this->getContainer()->get('app.policy');
        if ($isDaily) {
            $pending = $policyService->getPoliciesPendingCancellation(true, $prefix);
            $lines = [];
            foreach ($pending as $policy) {
                $lines[] = sprintf(
                    'Policy %s is pending cancellation on %s',
                    $policy->getPolicyNumber(),
                    $policy->getPendingCancellation()->format(\DateTime::ATOM)
                );
                $output->writeln($lines);
            }
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send('Policy Pending Cancellations Report', 'tech@so-sure.com', implode('<br />', $lines));
        } else {
            $lines = [];
            foreach ($cancelled as $policy) {
                $lines[] = sprintf('Cancelled Policy %s', $policy->getPolicyNumber());
                $output->writeln($lines);
            }
            if (count($lines) > 0) {
                $mailer = $this->getContainer()->get('app.mailer');
                $mailer->send(
                    'Policy Pending Cancellations - Cancelled',
                    'tech@so-sure.com',
                    implode('<br />', $lines)
                );
            }
        }

        $output->writeln(sprintf('Finished'));
    }
}
