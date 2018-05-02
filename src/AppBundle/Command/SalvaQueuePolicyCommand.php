<?php

namespace AppBundle\Command;

use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Service\SalvaExportService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;

class SalvaQueuePolicyCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:queue:policy')
            ->setDescription('Export a policies to salva')
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'policyNumber'
            )
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
            ->addOption(
                'cancel',
                null,
                InputOption::VALUE_REQUIRED,
                'Cancellation reason'
            )
            ->addOption(
                'requeue',
                null,
                InputOption::VALUE_REQUIRED,
                'Requeue the given policy number [created, updated, cancelled]'
            )
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
                'Max Number to process',
                1
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show items in the queue'
            )
            ->addOption(
                'requeue-date',
                null,
                InputOption::VALUE_REQUIRED,
                'Testing only! If requeuing for updated, set the policy change date'
            )
            ->addOption(
                'salva-version',
                null,
                InputOption::VALUE_REQUIRED,
                'If cancelling, use this specific version instead of latest - only if we get out of sync w/salva'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var SalvaExportService $salva */
        $salva = $this->getContainer()->get('app.salva');
        $policyNumber = $input->getOption('policyNumber');
        $prefix = $input->getOption('prefix');
        $cancel = $input->getOption('cancel');
        $clear = true === $input->getOption('clear');
        $requeue = $input->getOption('requeue');
        $show = true === $input->getOption('show');
        $process = $input->getOption('process');
        $requeueDateOption = $input->getOption('requeue-date');
        $version = $input->getOption('salva-version');
        $requeueDate = null;
        if ($requeueDateOption) {
            $requeueDate = new \DateTime($requeueDateOption);
        }

        if ($policyNumber) {
            $dm = $this->getManager();
            /** @var PhonePolicyRepository $repo */
            $repo = $dm->getRepository(SalvaPhonePolicy::class);
            /** @var SalvaPhonePolicy $phonePolicy */
            $phonePolicy = $repo->findOneBy(['policyNumber' => $policyNumber]);

            if ($cancel) {
                $responseId = $salva->cancelPolicy($phonePolicy, $cancel, $version);
                $output->writeln(sprintf(
                    "Policy %s was successfully cancelled. Response %s",
                    $policyNumber,
                    $responseId
                ));
            } elseif ($requeue) {
                $salva->queue($phonePolicy, $requeue, 0);
                $output->writeln(sprintf("Policy %s was successfully requeued for %s.", $policyNumber, $requeue));
            } else {
                if (!$phonePolicy) {
                    throw new \Exception(sprintf('Unable to find Policy %s', $policyNumber));
                }
                $responseId = $salva->sendPolicy($phonePolicy);
                $output->writeln(sprintf("Policy %s was successfully send. Response %s", $policyNumber, $responseId));
            }
        } elseif ($clear) {
            $salva->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } elseif ($show) {
            $data = $salva->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } else {
            $count = $salva->process($process, $prefix);
            $output->writeln(sprintf("Sent %s policy updates", $count));
        }
    }
}
