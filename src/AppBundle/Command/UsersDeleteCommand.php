<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Lead;
use AppBundle\Repository\UserRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;

class UsersDeleteCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var FOSUBUserProvider */
    protected $userService;

    /** @var MailerService */
    protected $mailerService;

    public function __construct(DocumentManager $dm, FOSUBUserProvider $userService, MailerService $mailerService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->userService = $userService;
        $this->mailerService = $mailerService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:users:delete')
            ->setDescription('Delete old users & leads')
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
        $date = \DateTime::createFromFormat('U', time());

        $now = \DateTime::createFromFormat('U', time());
        $oneMonth = \DateTime::createFromFormat('U', time());
        $oneMonth = $oneMonth->add(new \DateInterval('P1M'));

        $seventeenMonths = \DateTime::createFromFormat('U', time());
        $seventeenMonths = $seventeenMonths->sub(new \DateInterval('P17M'));

        // TODO: Resync optin with users

        $this->userService->resyncOpts();

        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
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
                $this->userService->deleteUser($user, false);
                $output->writeln(sprintf(
                    'Deleted user %s (%s)',
                    $user->getEmail(),
                    $user->getId()
                ));
            }
        }

        $this->dm->flush();

        /** @var DocumentRepository $repo */
        $repo = $this->dm->getRepository(Lead::class);
        $leads = $repo->findBy(['created' => ['$lte' => $seventeenMonths]]);
        $output->writeln(sprintf('%d leads are 17 months after creation', count($leads)));
        foreach ($leads as $lead) {
            if (!$skipDelete) {
                $this->userService->deleteLead($lead);
                $output->writeln(sprintf(
                    'Deleted lead %s (%s)',
                    $lead->getEmail(),
                    $lead->getId()
                ));
            }
        }
        $this->dm->flush();

        $output->writeln('Finished');
    }

    private function emailPendingDeletion(User $user)
    {
        $hash = SoSure::encodeCommunicationsHash($user->getEmail());
        $this->mailerService->sendTemplateToUser(
            'Sorry to see you go',
            $user,
            'AppBundle:Email:user/pendingDeletion.html.twig',
            ['user' => $user, 'hash' => $hash],
            'AppBundle:Email:user/pendingDeletion.txt.twig',
            ['user' => $user, 'hash' => $hash]
        );


    }
}
