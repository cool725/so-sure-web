<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class IntercomCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:intercom')
            ->setDescription('Sync with intercom')
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'email address to sync'
            )            
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getOption('email');
        if (!$email) {
            throw new \Exception('email required');
        }

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => strtolower($email)]);
        if (!$user) {
            throw new \Exception('unable to find user');
        }

        $intercom = $this->getContainer()->get('app.intercom');
        $resp = $intercom->update($user);
        print_r($resp);
    }
}
