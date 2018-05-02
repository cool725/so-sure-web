<?php

namespace AppBundle\Command;

use AppBundle\Service\ReceperioService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\PhonePolicy;

class ReceperioQueueCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:receperio:queue')
            ->setDescription('Rerun failed receperio checks')
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ReceperioService $receperio */
        $receperio = $this->getContainer()->get('app.imei');
        $clear = true === $input->getOption('clear');
        $show = true === $input->getOption('show');
        $process = $input->getOption('process');

        if ($clear) {
            if ($process > 0) {
                $receperio->clearQueue($process);
                $output->writeln(sprintf("Queue is cleared of %d messages", $process));
            } else {
                $receperio->clearQueue();
                $output->writeln(sprintf("Queue is cleared"));
            }
        } elseif ($show) {
            $data = $receperio->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d (%d shown)", $receperio->getQueueSize(), count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } else {
            $count = $receperio->process($process);
            $output->writeln(sprintf("Reprocessed %d checks", $count));
        }
    }
}
