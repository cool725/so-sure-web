<?php

namespace AppBundle\Command;

use AppBundle\Document\Cashback;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
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
            ->setDescription('Send email reminders about cashback status')
            ->addArgument(
                'status',
                InputArgument::REQUIRED,
                'Check for missing status instead of pending'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not email, just report on the email that would be sent'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Do not check for 2 week interval'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $status = $input->getArgument('status');

        if ($status == Cashback::STATUS_MISSING) {
            $lines = $this->policyService->cashbackMissingReminder($dryRun);
        } elseif ($status == Cashback::STATUS_PENDING_PAYMENT) {
            $date = \DateTime::createFromFormat('U', time())
                ->format('W');

            /*
            * If the option to $force is not passed and $date is not evenly divisible
            * do not run the command
            */
            if (!$force && ($date & 2)) {
                $output->writeln("Week not evenly divisible, not running this week");
                return;
            }

            $lines = $this->policyService->cashbackPendingReminder($dryRun);
        }

        $output->writeln(json_encode($lines, JSON_PRETTY_PRINT));
        $output->writeln(sprintf('Found %s matching policies, email sent', count($lines)));
    }
}
