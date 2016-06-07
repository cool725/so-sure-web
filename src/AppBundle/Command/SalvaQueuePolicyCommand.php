<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\PhonePolicy;

class SalvaQueuePolicyCommand extends ContainerAwareCommand
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
                'cancel',
                null,
                InputOption::VALUE_REQUIRED,
                'Cancellation reason'
            )
            ->addOption(
                'requeue',
                null,
                InputOption::VALUE_NONE,
                'Requeue the given policy number'
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $salva = $this->getContainer()->get('app.salva');
        $policyNumber = $input->getOption('policyNumber');
        $cancel = $input->getOption('cancel');
        $clear = true === $input->getOption('clear');
        $requeue = true === $input->getOption('requeue');
        $process = $input->getOption('process');

        if ($policyNumber) {
            $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
            $repo = $dm->getRepository(PhonePolicy::class);
            $phonePolicy = $repo->findOneBy(['policyNumber' => $policyNumber]);

            if ($cancel) {
                $responseId = $salva->cancelPolicy($phonePolicy, $cancel);
                $output->writeln(sprintf(
                    "Policy %s was successfully cancelled. Response %s",
                    $policyNumber,
                    $responseId
                ));
            } elseif ($requeue) {
                $salva->queue($phonePolicy);
                $output->writeln(sprintf("Policy %s was successfully requeued.", $policyNumber));
            } else {
                $responseId = $salva->sendPolicy($phonePolicy);
                $output->writeln(sprintf("Policy %s was successfully send. Response %s", $policyNumber, $responseId));
            }
        } elseif ($clear) {
            $salva->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } else {
            $count = $salva->process($process);
            $output->writeln(sprintf("Sent %s policy updates", $count));
        }
    }
}
