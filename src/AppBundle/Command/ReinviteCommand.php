<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Invitation\EmailInvitation;

class ReinviteCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:reinvite')
            ->setDescription('Email reinvitations')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'DateTime to check'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = $input->getOption('date');
        if (!$date) {
            $date = new \DateTime();
        } else {
            $date = new \DateTime($date);
        }

        $invitationService = $this->getContainer()->get('app.invitation');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(EmailInvitation::class);
        $invitations = $repo->findSystemReinvitations($date);

        foreach ($invitations as $invitation) {
            print sprintf("Reinviting %s\n", $invitation->getEmail());
            $invitationService->reinvite($invitation);
        }
    }
}
