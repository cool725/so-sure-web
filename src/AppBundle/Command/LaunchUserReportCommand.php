<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;

class LaunchUserReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:user:report')
            ->setDescription('Users report')
            ->addOption(
                'shortlink',
                null,
                InputOption::VALUE_NONE,
                'Use shortlink'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $useShortLink = true === $input->getOption('shortlink');

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $userRepo = $dm->getRepository(User::class);
        $router = $this->getContainer()->get('app.router');
        $shortLink = $this->getContainer()->get('app.shortlink');

        $users = $userRepo->findAll();
        foreach ($users as $user) {
            if (stripos($user->getEmailCanonical(), "@so-sure.com") === false) {
                $url = $router->generateUrl(
                    'homepage',
                    ['referral' => $user->getId()]
                );
                if ($useShortLink) {
                    $url = $shortLink->addShortLink($url);
                }
                print sprintf("%s,%s\n", $user->getEmailCanonical(), $url);
            }
        }
    }
}
