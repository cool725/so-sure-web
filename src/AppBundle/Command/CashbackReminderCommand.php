<?php

namespace AppBundle\Command;

use AppBundle\Document\Cashback;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Templating\EngineInterface;

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
        /** @var DocumentManager $dm */
        $dm = $this->getManager();

        /** @var MailerService $mailer */
        $mailer = $this->getContainer()->get('app.mailer');

        $results = $dm->createQueryBuilder(Cashback::class)
            ->field('status')
            ->equals(Cashback::STATUS_PENDING_PAYMENT)
            ->getQuery()
            ->execute();

        $policies = [];
        foreach ($results as $result) {
            $policies[] = sprintf(
                "Policy %s with status %s is pending cashback payment",
                $result->getPolicy()->getPolicyNumber(),
                $result->getPolicy()->getStatus()
            );
        }

        if ($input->getOption('dry-run')) {
            /** @var EngineInterface $templating */
            $templating = $this->getContainer()->get('templating');
            $email = $templating->render(
                'AppBundle:Email:policy/cashbackReminder.html.twig',
                ['policies' => $policies]
            );

            $output->writeln($email);
        } else {
            $mailer->sendTemplate(
                'Biweekly cashback report',
                ['dylan@so-sure.com', 'patrick@so-sure.com'],
                'AppBundle:Email:policy/cashbackReminder.html.twig',
                ['policies' => $policies]
            );

            $output->writeln(sprintf('Found %s cashback pending policies. Mail sent', count($policies)));
        }
    }
}
