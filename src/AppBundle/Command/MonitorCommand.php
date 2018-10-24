<?php

namespace AppBundle\Command;

use AppBundle\Service\MonitorService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorCommand extends ContainerAwareCommand
{
    /** @var MonitorService */
    protected $monitorService;

    public function __construct(MonitorService $monitorService)
    {
        parent::__construct();
        $this->monitorService = $monitorService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:monitor')
            ->setDescription('Run a so-sure monitor')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Name of monitor'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $message = $this->monitorService->run($name);
        $output->writeln($message);
    }
}
