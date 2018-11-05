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

        if ($input->getOption('dry-run')) {
            foreach ($results as $result) {
                $output->writeln(sprintf(
                    "Policy %s found with status %s",
                    $result->getPolicy()->getPolicyNumber(),
                    $result->getPolicy()->getStatus()
                ));
            }
        } else {
            $this->mailer->sendTemplate(
                'Biweekly cashback report',
                ['dylan@so-sure.com', 'patrick@so-sure.com'],
                'AppBundle:Email:cashback/cashbackReminder.html.twig',
                ['results' => $results]
            );

            $output->writeln(sprintf('Found %s cashback pending policies. Mail sent', count($results)));
        }
    }
}
