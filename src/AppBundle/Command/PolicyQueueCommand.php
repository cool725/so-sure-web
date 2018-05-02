<?php

namespace AppBundle\Command;

use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Policy;

class PolicyQueueCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:queue')
            ->setDescription('Generate policy documents')
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'Clear the queue (WARNING!!)'
            )
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Max Number to process (-1 to clear all queue)',
                1
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show items in the queue'
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy id to manually regenerate & resend the policy docs (will not affect the queue)'
            )
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy number to manually regeneate & resend the policy docs (will not affect the queue)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var PolicyService $policyService */
        $policyService = $this->getContainer()->get('app.policy');
        $clear = true === $input->getOption('clear');
        $show = true === $input->getOption('show');
        $process = $input->getOption('process');
        $policyId = $input->getOption('id');
        $policyNumber = $input->getOption('policyNumber');

        if ($clear) {
            if ($process > 0) {
                $policyService->clearQueue($process);
                $output->writeln(sprintf("Queue is cleared of %d messages", $process));
            } else {
                $policyService->clearQueue();
                $output->writeln(sprintf("Queue is cleared"));
            }
        } elseif ($show) {
            $data = $policyService->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d (%d shown)", $policyService->getQueueSize(), count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } elseif ($policyId) {
            $policy = $this->getPolicy($policyId);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy %s', $policyId));
            }
            $policyService->generatePolicyFiles($policy, true, 'bcc@so-sure.com');
            $output->writeln(sprintf("Re-generated policy (%s) docs and emailed", $policy->getId()));
        } elseif ($policyNumber) {
            $policy = $this->getPolicyByNumber($policyNumber);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy %s', $policyNumber));
            }
            $policyService->generatePolicyFiles($policy, true, 'bcc@so-sure.com');
            $output->writeln(sprintf("Re-generated policy (%s) docs and emailed", $policy->getPolicyNumber()));
        } else {
            $count = $policyService->process($process);
            $output->writeln(sprintf("Generated docs for %d policies", $count));
        }
    }

    private function getPolicy($policyId)
    {
        $repo = $this->getManager()->getRepository(Policy::class);

        return $repo->find($policyId);
    }

    private function getPolicyByNumber($policyNumber)
    {
        $repo = $this->getManager()->getRepository(Policy::class);

        return $repo->findOneBy(['policyNumber' => $policyNumber]);
    }
}
