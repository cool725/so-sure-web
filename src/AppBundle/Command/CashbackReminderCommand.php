<?php

namespace AppBundle\Command;

use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Classes\SoSure;

class CashbackReminderCommand extends BaseCommand
{
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
        /** @var PolicyService $policyService */
        $policyService = $this->getContainer()->get('app.policy');
        $lines = $policyService->cashbackReminder($dryRun);
        $output->writeln(json_encode($lines, JSON_PRETTY_PRINT));
    }
}
