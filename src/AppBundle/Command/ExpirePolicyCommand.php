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

class ExpirePolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:expire')
            ->setDescription('Expire unpaid policies')
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
        $dryRun = true === $input->getOption('dry-run');
        $prefix = $input->getOption('prefix');

        $policyService = $this->getContainer()->get('app.policy');
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);

        $policies = $policyRepo->findBy(['status' => Policy::STATUS_UNPAID]);
        $count = 0;
        foreach ($policies as $policy) {
            if ($policy->shouldExpirePolicy($prefix)) {
                if (!$dryRun) {
                    $policyService->cancel($policy, Policy::CANCELLED_UNPAID);
                    $output->writeln(sprintf('Cancelled Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
                } else {
                    $output->writeln(sprintf(
                        'Dry-Run - Should Cancel Policy %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    ));
                }
                $count++;
            }
        }

        $output->writeln(sprintf('Finished. %s policies cancelled', $count));
    }
}
