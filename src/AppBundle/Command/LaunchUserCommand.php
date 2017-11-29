<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;

class LaunchUserCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:user:add')
            ->setDescription('Add user to db and mailchimp')
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'Email to add'
            )->addOption(
                'resend',
                null,
                InputOption::VALUE_NONE,
                'Resend launch email'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $resend = true === $input->getOption('resend');
        $shortLink = $this->getContainer()->get('app.shortlink');
        $router = $this->getContainer()->get('app.router');
        $launchUser = $this->getContainer()->get('app.user.launch');

        $user = new User();
        $user->setEmail($email);
        $user = $launchUser->addUser($user, $resend)['user'];
        $url = $router->generateUrl('homepage', ['referral' => $user->getId()]);
        $url = $shortLink->addShortLink($url);
        $output->writeln(sprintf('%s,%s', $email, $url));
    }
}
