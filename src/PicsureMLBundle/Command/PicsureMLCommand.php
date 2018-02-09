<?php

namespace PicsureMLBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use PicsureMLBundle\Service\PicsureMLService;

class PicsureMLCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:picsure:ml')
            ->setDescription('Picsure ML commands')
            ->addOption(
                'sync',
                null,
                InputOption::VALUE_NONE,
                'Sync pic-sure images with training data'
            )
            ->addOption(
                'output',
                null,
                InputOption::VALUE_NONE,
                'Output csv of training data'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sync = true === $input->getOption('sync');
        $csv = true === $input->getOption('output');

        if ($sync) {
            $output->writeln(sprintf('Sync...'));
            $picsureMLService = $this->getContainer()->get('picsureml.picsureml');
            $picsureMLService->sync();
            $output->writeln(sprintf('Done'));
        } elseif ($csv) {
            $output->writeln(sprintf('Output...'));
            $picsureMLService = $this->getContainer()->get('picsureml.picsureml');
            $picsureMLService->output($output);
            $output->writeln(sprintf('Done'));
        }

    }
}
