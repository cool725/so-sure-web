<?php

namespace AppBundle\Command;

use AppBundle\Service\RateLimitService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class RateLimitCommand extends ContainerAwareCommand
{
    /** @var RateLimitService  */
    protected $rateLimitService;

    public function __construct(RateLimitService $rateLimitService)
    {
        parent::__construct();
        $this->rateLimitService = $rateLimitService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:ratelimit')
            // @codingStandardsIgnoreStart
            ->setDescription('Show/Clear Rate Limits')
            // @codingStandardsIgnoreEnd
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'clear limits'
            )
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                'all, address, imei, serial, login, policy, reset, token, user-login'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $type = $input->getArgument('type');
        $clear = true === $input->getOption('clear');

        $output->writeln(json_encode($this->rateLimitService->show($type), JSON_PRETTY_PRINT));
        if ($clear) {
            $this->rateLimitService->clearByType($type);
            $output->writeln('Rate limits cleared');
        }
    }
}
