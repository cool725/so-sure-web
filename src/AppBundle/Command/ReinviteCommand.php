<?php

namespace AppBundle\Command;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Repository\Invitation\EmailInvitationRepository;
use AppBundle\Service\InvitationService;
use AppBundle\Service\InvoiceService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Invitation\EmailInvitation;

class ReinviteCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var InvitationService */
    protected $invitationService;

    public function __construct(DocumentManager $dm, InvitationService $invitationService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->invitationService = $invitationService;
    }

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

        /** @var EmailInvitationRepository $repo */
        $repo = $this->dm->getRepository(EmailInvitation::class);
        $invitations = $repo->findSystemReinvitations($date);

        foreach ($invitations as $invitation) {
            /** @var EmailInvitation $invitation */
            print sprintf("Reinviting %s\n", $invitation->getEmail());
            $this->invitationService->reinvite($invitation);
        }
    }
}
