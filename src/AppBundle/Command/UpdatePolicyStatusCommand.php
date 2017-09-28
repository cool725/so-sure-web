<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Feature;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\User;

class UpdatePolicyStatusCommand extends BaseCommand
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
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy Id - will ignore feature flags'
            )
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'Skip sending email. Development use only!'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ignoreLineCount = 0;
        $lines = [];
        $lines[] = '';
        $ignoreLineCount++;
        $dryRun = true === $input->getOption('dry-run');
        $skipEmail = true === $input->getOption('skip-email');
        $prefix = $input->getOption('prefix');
        $policyId = $input->getOption('id');

        $policyService = $this->getContainer()->get('app.policy');
        $featureService = $this->getContainer()->get('app.feature');
        if ($skipEmail) {
            $policyService->setMailer(null);
        }

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
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

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
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        if ($policyId) {
            $repo = $this->getManager()->getRepository(Policy::class);
            $policy = $repo->find($policyId);
            if (!$policy) {
                throw new \Exception('Unable to find policy');
            }
            if ($policy->canCreatePendingRenewal()) {
                if (!$dryRun) {
                    $policyService->createPendingRenewal($policy);
                    $lines[] = sprintf(
                        'Created partial policy for %s/%s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                }
            }
        } elseif ($featureService->isEnabled(Feature::FEATURE_RENEWAL)) {
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
            $ignoreLineCount++;
            $lines[] = '';
            $ignoreLineCount++;
        } else {
            $lines[] = 'Renewal feature flag not enabled. Skipping pending renewal policy creation.';
            $ignoreLineCount++;
            $lines[] = '';
            $ignoreLineCount++;
        }

        // Renew Policies (Pending Renewal -> Renewed)
        $renewed = $policyService->renewPolicies($prefix, $dryRun);
        $copy = 'Renewed Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should renew Policy';
        }
        foreach ($renewed as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s renewed policies processed', count($renewed));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        // Activate Policies (Renewed -> Active)
        $renewal = $policyService->activateRenewalPolicies($prefix, $dryRun);
        $copy = 'Activated Renewal Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should activate Renewal Policy';
        }
        foreach ($renewal as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s activated renewal policies processed', count($renewal));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        // Unrenew Policies (Renew Declined -> UnRenewed)
        $unrenewed = $policyService->unrenewPolicies($prefix, $dryRun);
        $copy = 'Unrenewed Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should unrenew Policy';
        }
        foreach ($unrenewed as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s unrenewed policies processed', count($unrenewed));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        // Expire Policies - (Active/Unpaid)
        $expired = $policyService->expireEndingPolicies($prefix, $dryRun);
        $copy = 'Expire Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should expire Policy';
        }
        foreach ($expired as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s expire (claimable) policies processed', count($expired));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        // Fully Expire Policies - (from Expired-Claimable)
        $fullyExpired = $policyService->fullyExpireExpiredClaimablePolicies($prefix, $dryRun);
        $copy = 'Fully Expire Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should fully expire Policy';
        }
        foreach ($fullyExpired as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s fully expire policies processed', count($fullyExpired));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        $output->writeln(join(PHP_EOL, $lines));

        # 5 lines for each section output
        if (count($lines) > $ignoreLineCount) {
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
