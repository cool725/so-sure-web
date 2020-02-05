<?php

namespace AppBundle\Command;

use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\DaviesHandlerClaim;

class ClaimsNotifyPolicyPendingCancellationCommand extends ContainerAwareCommand
{
    /** @var PolicyService  */
    protected $policyService;

    public function __construct(PolicyService $policyService)
    {
        parent::__construct();
        $this->policyService = $policyService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:claims:notify-pending-cancellation')
            ->setDescription('Notify claims handlers of policies w/open claims to be cancelled soon')
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days before cancellation to notify (default: 5)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $days = $input->getOption('days');
        $count = $this->policyService->notifyPendingCancellations($days);
        $output->writeln(sprintf('%d policies with open claims will be cancelled. Email report sent.', $count));
    }
}
