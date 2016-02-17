<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $shortLink = $this->getContainer()->get('app.shortlink');
        $router = $this->getContainer()->get('router');
        $launchUser = $this->getContainer()->get('app.user.launch');

        $user = new User();
        $user->setEmail($email);
        $user = $launchUser->addUser($user);
        $url = $router->generate('homepage', ['referral' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $url = $shortLink->addShortLink($url);
        $output->writeln(sprintf('%s,%s', $email, $url));
    }
}
