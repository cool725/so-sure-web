<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class StatsdCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('statsd')
            ->setDescription('Send a statsd')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Metric name'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');

        $statsd = $this->getContainer()->get('statsd');
        $statsd->increment($name);
    }
}
