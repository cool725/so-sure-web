<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\User;

class UpdatePolicyStatusCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:update-status')
            ->setDescription('Transition policy statuses. Unpaid, Expired, Pending renewal, etc')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not cancel policies, just report on policies that would be cancelled'
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
        $lines = [];
        $dryRun = true === $input->getOption('dry-run');
        $prefix = $input->getOption('prefix');

        $policyService = $this->getContainer()->get('app.policy');

        // Unpaid Policies - Cancel
        $cancelled = $policyService->cancelUnpaidPolicies($prefix, $dryRun);
        $copy = 'Cancelled Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should cancel Policy (unpaid)';
        }
        foreach ($cancelled as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s cancelled policies processed', count($cancelled));

        // Pending Cancellation Policies - Cancel
        $pendingCancellation = $policyService->cancelPoliciesPendingCancellation($prefix, $dryRun);
        $copy = 'Pending Cancellation Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should cancel Policy (pending cancellation)';
        }
        foreach ($pendingCancellation as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s pending cancellation policies processed', count($pendingCancellation));

        // Create Polices - Pending Renewal
        $pendingRenewal = $policyService->createPendingRenewalPolicies($prefix, $dryRun);
        $copy = 'Partial Renewal Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should create Partial Renewal Policy';
        }
        foreach ($pendingRenewal as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s partial renewal policies processed', count($pendingRenewal));

        // Activate Policies - Renewed
        $renewal = $policyService->activateRenewalPolicies($prefix, $dryRun);
        $copy = 'Activated Renewal Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should activate Renewal Policy';
        }
        foreach ($renewal as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s activated renewal policies processed', count($renewal));

        // Expire Policies - (Active/Unpaid)
        $expired = $policyService->expireEndingPolicies($prefix, $dryRun);
        $copy = 'Expire Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should expire Policy';
        }
        foreach ($expired as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s expired policies processed', count($expired));

        $output->writeln(join(PHP_EOL, $lines));

        # 5 lines for each section output
        if (count($lines) > 5) {
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send(
                'Updated Policy Status',
                'tech@so-sure.com',
                implode('<br />', $lines)
            );
            $output->writeln('Emailed results to tech@so-sure.com');
        }
        $output->writeln('Finished');
    }
}
