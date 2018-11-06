<?php

namespace AppBundle\Command;

use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CashbackReminderCommand extends ContainerAwareCommand
{
    /** @var PolicyService */
    protected $policyService;

    public function __construct(PolicyService $policyService)
    {
        parent::__construct();
        $this->policyService = $policyService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:cashback:reminder')
            ->setDescription('Send email reminders about cashback')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not email, just report on cashback email that would be sent'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = true === $input->getOption('dry-run');
        $lines = $this->policyService->cashbackReminder($dryRun);
        $cashbacks = $this->policyService->cashbackPending($dryRun);

        if ($dryRun) {
            foreach ($cashbacks as $cashback) {
                $output->writeln(sprintf(
                    "Policy %s found with pending status",
                    $cashback
                ));
            }

            $output->writeln(json_encode($lines, JSON_PRETTY_PRINT));
        }

        $output->writeln(sprintf('Found %s cashback pending policies. Mail sent', count($cashbacks)));
    }
}
