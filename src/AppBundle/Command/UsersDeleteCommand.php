<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\JudopayService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;

class UsersDeleteCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:users:delete')
            ->setDescription('Delete old users')
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'do not send warning email'
            )
            ->addOption(
                'notify-all',
                null,
                InputOption::VALUE_NONE,
                'This will re-notify everyone that is eligeable to be deleted, that they soon will be'
            )
            ->addOption(
                'skip-delete',
                null,
                InputOption::VALUE_NONE,
                'do not delete users'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skipEmail = true === $input->getOption('skip-email');
        $skipDelete = true === $input->getOption('skip-delete');
        $notifyAll = true === $input->getOption('notify-all');
        $date = new \DateTime();

        $now = new \DateTime();
        $oneMonth = new \DateTime();
        $oneMonth = $oneMonth->add(new \DateInterval('P1M'));

        $seventeenMonths = new \DateTime();
        $seventeenMonths = $seventeenMonths->sub(new \DateInterval('P17M'));

        // TODO: Resync optin with users

        /** @var UserRepository $repo */
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findBy(['created' => ['$lte' => $seventeenMonths]]);
        $output->writeln(sprintf('%d users are 17 months after creation', count($users)));
        foreach ($users as $user) {
            /** @var User $user */

            // should we notify user they are about to be deleted in the future?
            if (!$skipEmail && $user->shouldDelete($oneMonth) && $user->shouldNotifyDelete()) {
                $this->emailPendingDeletion($user);
                $output->writeln(sprintf(
                    'Notified %s (%s) about pending deletion',
                    $user->getEmail(),
                    $user->getId()
                ));
            }

            // probably won't notify much (one off), but if so, notify all users about to be deleted
            if (!$skipEmail && $notifyAll && $user->shouldDelete()) {
                $this->emailPendingDeletion($user);
                $output->writeln(sprintf(
                    'Notified %s (%s) about pending deletion',
                    $user->getEmail(),
                    $user->getId()
                ));
            }

            if (!$skipDelete && $user->shouldDelete()) {
                $this->getManager()->remove($user);
                $output->writeln(sprintf(
                    'Deleted user %s (%s)',
                    $user->getEmail(),
                    $user->getId()
                ));
            }
        }

        $this->getManager()->flush();

        $output->writeln('Finished');
    }

    private function emailPendingDeletion(User $user)
    {
        $hash = SoSure::encodeCommunicationsHash($user->getEmail());
        /** @var MailerService $mailer */
        $mailer = $this->getContainer()->get('app.mailer');
        $mailer->sendTemplate(
            'Sorry to see you go',
            $user->getEmail(),
            'AppBundle:Email:user/pendingDeletion.html.twig',
            ['user' => $user, 'hash' => $hash],
            'AppBundle:Email:user/pendingDeletion.txt.twig',
            ['user' => $user, 'hash' => $hash]
        );


    }
}
