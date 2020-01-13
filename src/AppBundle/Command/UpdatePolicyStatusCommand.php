<?php

namespace AppBundle\Command;

use AppBundle\Document\PhonePolicy;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Service\FeatureService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Feature;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\User;

class UpdatePolicyStatusCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var PolicyService */
    protected $policyService;

    /** @var FeatureService */
    protected $featureService;

    /** @var MailerService */
    protected $mailerService;

    public function __construct(
        DocumentManager $dm,
        PolicyService $policyService,
        FeatureService $featureService,
        MailerService $mailerService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->policyService = $policyService;
        $this->featureService = $featureService;
        $this->mailerService = $mailerService;
    }

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
                'dry-unpaid',
                null,
                InputOption::VALUE_NONE,
                'Do not cancel unpaid policies but do everything else'
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
            ->addOption(
                'skip-unpaid-timecheck',
                null,
                InputOption::VALUE_NONE,
                'Skip a timeframe check for unpaid policy cancellations (15 min days in unpaid state)'
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
        $dryUnpaid = true === $input->getOption('dry-unpaid');
        $skipEmail = true === $input->getOption('skip-email');
        $policyId = $input->getOption('id');
        $skipUnpaidMinTimeframeCheck = $input->getOption('skip-unpaid-timecheck');

        /** @var PolicyRepository $repo */
        $repo = $this->dm->getRepository(Policy::class);
        if ($skipEmail) {
            $this->policyService->setMailer(null);
        }

        // Metrics
        $copy = 'Metrics set Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should set Metrics for Policy';
        }
        $metrics = $this->policyService->runMetrics($dryRun);
        foreach ($metrics as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
            $ignoreLineCount++;
        }
        $lines[] = sprintf('%s metrics policies processed', count($metrics));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        // Unpaid Policies - Cancel
        $cancelled = $this->policyService->cancelUnpaidPolicies(
            $dryRun || $dryUnpaid,
            $skipUnpaidMinTimeframeCheck
        );
        $copy = 'Unpaid cancelled policy';
        if ($dryRun || $dryUnpaid) {
            $copy = 'Dry Run - Should cancel Policy (unpaid)';
        }
        foreach ($cancelled as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s unpaid cancelled policies processed', count($cancelled));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        // Picsure Required Policies - Cancel
        $cancelled = $this->policyService->cancelOverduePicsurePolicies($dryRun);
        $copy = 'Required Picsure overdue cancelled policy';
        if ($dryRun) {
            $copy = 'Dry Run - should cancel policy (picsure overdue)';
        }
        foreach ($cancelled as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%d picsure required overdue policies processed', count($cancelled));
        $ignoreLineCount += 2;
        $lines[] = '';

        // Pending Cancellation Policies - Cancel
        $pendingCancellation = $this->policyService->cancelPoliciesPendingCancellation($dryRun);
        $copy = 'User Requested Cancellation Policy';
        if ($dryRun) {
            $copy = 'Dry Run - Should cancel Policy (user requested [pending] cancellation)';
        }
        foreach ($pendingCancellation as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s user requested (pending) cancellation policies processed', count($pendingCancellation));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        if ($policyId) {
            /** @var Policy $policy */
            $policy = $repo->find($policyId);
            if (!$policy) {
                throw new \Exception('Unable to find policy');
            }
            if ($policy->canCreatePendingRenewal()) {
                if (!$dryRun) {
                    $this->policyService->createPendingRenewal($policy);
                    $lines[] = sprintf(
                        'Created partial policy for %s/%s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                }
            }
        } elseif ($this->featureService->isEnabled(Feature::FEATURE_RENEWAL)) {
            // Create Polices - Pending Renewal
            $pendingRenewal = $this->policyService->createPendingRenewalPolicies($dryRun);
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
        $renewed = $this->policyService->renewPolicies($dryRun);
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

        // Expire Policies - (Active/Unpaid)
        $expired = $this->policyService->expireEndingPolicies($dryRun);
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

        // Activate Policies (Renewed -> Active)
        $renewal = $this->policyService->activateRenewalPolicies($dryRun);
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
        $unrenewed = $this->policyService->unrenewPolicies($dryRun);
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

        // Fully Expire Policies - (from Expired-Claimable)
        $fullyExpired = $this->policyService->fullyExpireExpiredClaimablePolicies($dryRun);
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

        // set unpaid Cancelled Mandates
        $unpaid = $this->policyService->setUnpaidForCancelledMandate($dryRun);
        $copy = 'Set Unpaid for Cancelled Mandates';
        if ($dryRun) {
            $copy = 'Dry Run - Should set unpaid';
        }
        foreach ($unpaid as $id => $number) {
            $lines[] = sprintf('%s %s / %s', $copy, $number, $id);
        }
        $lines[] = sprintf('%s cancelled mandate policies set to unpaid', count($unpaid));
        $ignoreLineCount++;
        $lines[] = '';
        $ignoreLineCount++;

        $output->writeln(join(PHP_EOL, $lines));

        # 5 lines for each section output
        if (count($lines) > $ignoreLineCount) {
            $this->mailerService->send(
                'Updated Policy Status',
                'tech+ops@so-sure.com',
                implode('<br />', $lines)
            );
            $output->writeln('Emailed results to tech+ops@so-sure.com');
        }
        $output->writeln('Finished');
    }
}
