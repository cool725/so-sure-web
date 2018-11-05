<?php

namespace AppBundle\Command;

use AppBundle\Document\Cashback;
use AppBundle\Document\Policy;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Templating\EngineInterface;

class CashbackReminderCommand extends ContainerAwareCommand
{
    /** @var DocumentManager */
    protected $dm;

    /** @var PolicyService */
    protected $policyService;

    /** @var MailerService */
    protected $mailer;

    public function __construct(DocumentManager $dm, PolicyService $policyService, MailerService $mailer)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->policyService = $policyService;
        $this->mailer = $mailer;
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
