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
            ->addOption(
                'versionNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Version of the training data'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sync = true === $input->getOption('sync');
        $csv = true === $input->getOption('output');
        $version = $input->getOption('versionNumber');
        /** @var PicsureMLService $picsureMLService */
        $picsureMLService = $this->getContainer()->get('picsureml.picsureml');

        if ($sync) {
            $output->writeln(sprintf('Sync...'));
            $picsureMLService->sync();
            $output->writeln(sprintf('Done'));
        } elseif ($csv) {
            if (!empty($version) && (int)$version > 0) {
                $output->writeln(sprintf('Output...'));
                $result = $picsureMLService->output((int)$version);
                if ($result !== true) {
                    $output->writeln($result);
                }
                $output->writeln(sprintf('Done'));
            } else {
                $output->writeln(sprintf('Error: version number required'));
            }
        }

    }
}
