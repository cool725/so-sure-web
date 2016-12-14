<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class AppAnnieCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:appannie')
            ->setDescription('Run app annie')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date to run'
            )
            ->addOption(
                'end-date',
                null,
                InputOption::VALUE_REQUIRED,
                'end date to run to (implies skip-save)'
            )
            ->addOption(
                'skip-save',
                null,
                InputOption::VALUE_NONE,
                'do not save output'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skipSave = true === $input->getOption('skip-save');
        $dateOption = $input->getOption('date');
        $date = new \DateTime('-1 day');
        if ($dateOption) {
            $date = new \DateTime($dateOption);
        }
        $endDateOption = $input->getOption('end-date');
        $endDate = null;
        if ($endDateOption) {
            $endDate = new \DateTime($endDateOption);
            $skipSave = true;
        }

        $appAnnie = $this->getAppAnnie();
        $results = $appAnnie->run($date, $endDate, !$skipSave);
        $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
    }
    
    private function getAppAnnie()
    {
        return $this->getContainer()->get('app.annie');
    }
}
