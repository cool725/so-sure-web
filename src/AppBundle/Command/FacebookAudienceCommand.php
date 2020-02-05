<?php

namespace AppBundle\Command;

use AppBundle\Service\FacebookService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class FacebookAudienceCommand extends ContainerAwareCommand
{
    /** @var FacebookService */
    protected $facebookService;

    public function __construct(FacebookService $facebookService)
    {
        parent::__construct();
        $this->facebookService = $facebookService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:facebook:audience')
            ->setDescription('create custom & lookalike audiences')
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional prefix'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Pretend its this date (month)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $prefix = $input->getOption('prefix');
        $dateOption = $input->getOption('date');
        if ($dateOption) {
            $date = new \DateTime($dateOption);
        } else {
            $date = new \DateTime();
            $date = $date->sub(new \DateInterval('P1M'));
        }

        $result = $this->facebookService->monthlyLookalike($date, $prefix);

        $output->writeln(sprintf('Finished'));
    }
}
