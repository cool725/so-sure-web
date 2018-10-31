<?php

namespace AppBundle\Command;

use AppBundle\Document\Cashback;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
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
use Symfony\Component\Templating\EngineInterface;

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
        /** @var DocumentManager $dm */
        $dm = $this->getManager();

        /** @var MailerService $mailer */
        $mailer = $this->getContainer()->get('app.mailer');

        $debug = $input->getOption('dry-run');

        $results = $dm->createQueryBuilder(Cashback::class)
            ->field('status')
            ->equals(Cashback::STATUS_PENDING_PAYMENT)
            ->getQuery()
            ->execute();

        $policies = [];
        foreach ($results as $result) {
            $policies[] = sprintf("Policy %s with status %s is pending cashback payment", $result->getPolicy()->getPolicyNumber(), $result->getPolicy()->getStatus());
        }

        $mailer->sendTemplate(
            'Biweekly cashback report',
            ['dylan@so-sure.com', 'patrick@so-sure.com'],
            'AppBundle:Email:policy/cashbackReminder.html.twig',
            ['policies' => $policies]
        );

        if ($debug) {
            /** @var EngineInterface $templating */
            $templating = $this->getContainer()->get('templating');
            $email = $templating->render(
                'AppBundle:Email:policy/cashbackReminder.html.twig',
                ['policies' => $policies]
            );

            $output->writeln($email);
        }

        $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
    }
}
