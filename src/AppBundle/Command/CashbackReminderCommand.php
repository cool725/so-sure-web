<?php

namespace AppBundle\Command;

use AppBundle\Document\Cashback;
use AppBundle\Document\Policy;
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

    /** @var MailerService */
    protected $mailer;

    public function __construct(DocumentManager $dm, MailerService $mailer)
    {
        parent::__construct();
        $this->dm = $dm;
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

        $results = $this->dm->createQueryBuilder(Cashback::class)
            ->field('status')
            ->equals(Cashback::STATUS_PENDING_PAYMENT)
            ->getQuery()
            ->execute();

        $cashbacks = [];
        foreach ($results as $result) {
            /** @var Cashback $result */
            $cashbacks[] = [
                'id' => $result->getPolicy()->getId(),
                'number' => $result->getPolicy()->getPolicyNumber(),
                'status' => $result->getPolicy()->getStatus()
            ];
        }

        if ($input->getOption('dry-run')) {
            foreach ($cashbacks as $cashback) {
                $output->writeln("Policy {$cashback['number']} found with status {$cashback['status']}");
            }
        } else {
            $this->mailer->sendTemplate(
                'Biweekly cashback report',
                ['dylan@so-sure.com', 'patrick@so-sure.com'],
                'AppBundle:Email:policy/cashbackReminder.html.twig',
                ['policies' => $cashbacks]
            );

            $output->writeln(sprintf('Found %s cashback pending policies. Mail sent', count($cashbacks)));
        }
    }
}
