<?php

namespace AppBundle\Command;

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
use AppBundle\Document\Cashback;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Classes\SoSure;

class PrivledgedUserPasswordResetEmailCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:password:privledged-user-reset')
            ->setDescription('Reset privledged user passwords which are too old')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $users = [];
        $roles = ['ROLE_CLAIMS', 'ROLE_EMPLOYEE', 'ROLE_ADMIN'];
        foreach ($roles as $role) {
            foreach ($repo->findUsersInRole($role) as $user) {
                if (!isset($users[$user->getId()])) {
                    $users[$user->getId()] = $user;
                }
            }
        }
        $locked = [];
        foreach ($users as $user) {
            if ($user->isPasswordChangeRequired() && $user->isEnabled()) {
                $user->setEnabled(false);
                $this->sendLockedEmail($user);
                $locked[] = $user->getEmail();
            }
        }
        $dm->flush();

        $output->writeln(sprintf(
            'Locked & Notified %d Privledged Users [%s]',
            count($locked),
            json_encode($locked)
        ));
    }

    private function sendLockedEmail(User $user)
    {
        $router = $this->getContainer()->get('app.router');
        $resetUrl = $router->generateUrl('fos_user_resetting_request', ['email' => $user->getEmail()]);
        $mailer = $this->getContainer()->get('app.mailer');
        $mailer->sendTemplate(
            'Your so-sure password needs changing (Account is now locked)',
            $user->getEmail(),
            'AppBundle:Email:user/passwordResetRequired.html.twig',
            ['user' => $user, 'reset_url' => $resetUrl],
            'AppBundle:Email:user/passwordResetRequired.txt.twig',
            ['user' => $user, 'reset_url' => $resetUrl]
        );
    }
}
