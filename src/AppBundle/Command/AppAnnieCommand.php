<?php

namespace AppBundle\Command;

use AppBundle\Service\AppAnnieService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class AppAnnieCommand extends ContainerAwareCommand
{
    protected $appAnnieService;

    public function __construct(AppAnnieService $appAnnieService)
    {
        parent::__construct();
        $this->appAnnieService = $appAnnieService;
    }

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
            ->addOption(
                'ignore-zero',
                null,
                InputOption::VALUE_NONE,
                'ok if the results are 0; perhaps due to a bad download app day'
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'show debug output'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skipSave = true === $input->getOption('skip-save');
        $dateOption = $input->getOption('date');
        $ignoreZero = $input->getOption('ignore-zero');
        $debug = $input->getOption('debug');

        $date = new \DateTime('-4 day');
        if ($dateOption) {
            $date = new \DateTime($dateOption);
        }
        $endDateOption = $input->getOption('end-date');
        $endDate = null;
        if ($endDateOption) {
            $endDate = new \DateTime($endDateOption);
            $skipSave = true;
        }

        $output->writeln(sprintf('Checking %s', $date->format(\DateTime::ATOM)));
        $results = $this->appAnnieService->run($date, $endDate, !$skipSave, $ignoreZero);
        if ($debug) {
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
        } else {
            $output->writeln(sprintf("Apple %d", $results['apple']['downloads']));
            $output->writeln(sprintf("Google %d", $results['google']['downloads']));
        }
    }
}
