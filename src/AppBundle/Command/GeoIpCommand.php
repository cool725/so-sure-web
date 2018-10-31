<?php

namespace AppBundle\Command;

use AppBundle\Service\MaxMindIpService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class GeoIpCommand extends ContainerAwareCommand
{
    /** @var MaxMindIpService */
    protected $maxMindIpService;

    public function __construct(MaxMindIpService $maxMindIpService)
    {
        parent::__construct();
        $this->maxMindIpService = $maxMindIpService;
    }

    protected function configure()
    {
        $this
            ->setName('geoip')
            ->setDescription('Test geoip')
            ->addArgument(
                'ip',
                InputArgument::REQUIRED,
                'Ip Address to check'
            )
            ->addOption(
                'query-type',
                null,
                InputOption::VALUE_REQUIRED,
                'city'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ip = $input->getArgument('ip');
        $queryType = $input->getOption('query-type');

        $output->writeln(json_encode($this->maxMindIpService->find($ip, $queryType)));
    }
}
