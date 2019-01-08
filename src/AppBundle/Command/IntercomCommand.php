<?php

namespace AppBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\IntercomService;

class IntercomCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var IntercomService  */
    protected $intercom;

    public function __construct(DocumentManager $dm, IntercomService $intercomService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->intercom = $intercomService;
    }

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
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'Clear the queue (WARNING!!)'
            )
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Max Number to process',
                50
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show items in the queue'
            )
            ->addOption(
                'requeue',
                null,
                InputOption::VALUE_NONE,
                'Requeue the given user (if email param given) or all users'
            )
            ->addOption(
                'update',
                null,
                InputOption::VALUE_NONE,
                'Update user immediately (requires email param)'
            )
            ->addOption(
                'count-queue',
                null,
                InputOption::VALUE_NONE,
                'Count the queue'
            )
            ->addOption(
                'convert-lead',
                null,
                InputOption::VALUE_REQUIRED,
                'Force a lead to user conversion (email address)'
            )
            ->addOption(
                'undelete',
                null,
                InputOption::VALUE_NONE,
                'Resync regardless of deletion (will undelete if deleted) - requires email'
            )
            ->addOption(
                'maintenance',
                null,
                InputOption::VALUE_NONE,
                'Check for unsubscriptions & clear out old lead/users'
            )
            ->addOption(
                'lead-maintenance',
                null,
                InputOption::VALUE_NONE,
                'Check for unsubscriptions & clear out old leads'
            )
            ->addOption(
                'user-maintenance',
                null,
                InputOption::VALUE_NONE,
                'Check for unsubscriptions & clear out old users'
            )
            ->addOption(
                'pending-invites',
                null,
                InputOption::VALUE_NONE,
                'Send events for outstanding pending inbound invitations'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $clear = true === $input->getOption('clear');
        $show = true === $input->getOption('show');
        $process = $input->getOption('process');
        $email = $input->getOption('email');
        $requeue = true === $input->getOption('requeue');
        $convertLead = $input->getOption('convert-lead');
        $undelete = true === $input->getOption('undelete');
        $maintenance = true === $input->getOption('maintenance');
        $leadMaintenance = true === $input->getOption('lead-maintenance');
        $userMaintenance = true === $input->getOption('user-maintenance');
        $pendingInvites = true === $input->getOption('pending-invites');
        $countQueue = true === $input->getOption('count-queue');
        $update = true === $input->getOption('update');

        if ($email) {
            $user = $this->getUser($email);

            if ($requeue) {
                $resp = $this->intercom->queue($user);
                $output->writeln(sprintf('User %s was requeued', $user->getId()));
            } elseif ($update) {
                $resp = $this->intercom->update($user);
                $output->writeln(sprintf('User %s was updated. Response:', $user->getId()));
                $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
            } else {
                if ($user->getIntercomId()) {
                    $resp = $this->intercom->getIntercomUser($user);
                } else {
                    $resp = $this->intercom->getIntercomUser($user, false);
                }
                $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
            }
        } elseif ($convertLead) {
            $user = $this->getUser($convertLead);
            $resp = $this->intercom->convertLead($user);
            $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        } elseif ($clear) {
            $this->intercom->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } elseif ($countQueue) {
            $count = $this->intercom->countQueue();
            $output->writeln(sprintf("%d in queue", $count));
        } elseif ($show) {
            $data = $this->intercom->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } elseif ($requeue) {
            $count = 0;
            foreach ($this->getAllUsers() as $user) {
                $this->intercom->queue($user);
                $count++;
            }
            $output->writeln(sprintf("Queued %d Users", $count));
        } elseif ($maintenance) {
            $output->writeln(implode(PHP_EOL, $this->intercom->maintenance()));
            $output->writeln(sprintf("Finished running maintenance"));
        } elseif ($leadMaintenance) {
            $output->writeln(implode(PHP_EOL, $this->intercom->leadsMaintenance()));
            $output->writeln(sprintf("Finished running lead maintenance"));
        } elseif ($userMaintenance) {
            $output->writeln(implode(PHP_EOL, $this->intercom->usersMaintenance()));
            $output->writeln(sprintf("Finished running user maintenance"));
        } elseif ($pendingInvites) {
            $count = 0;
            foreach ($this->getPendingInvites() as $invitation) {
                $this->intercom->queueInvitation($invitation, IntercomService::QUEUE_EVENT_INVITATION_PENDING);
                $count++;
            }
            $output->writeln(sprintf("Queued %d Pending Invitations", $count));
        } else {
            $count = $this->intercom->process($process);
            $output->writeln(sprintf("Sent %s updates", $count));
        }
    }

    private function getAllUsers()
    {
        $repo = $this->getUserRepository();

        return $repo->findAll();
    }

    private function getPendingInvites()
    {
        $repo = $this->getEmailInvitationRepository();

        return $repo->findPendingInvitations();
    }

    private function getUser($email)
    {
        $repo = $this->getUserRepository();
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if (!$user) {
            throw new \Exception('unable to find user');
        }

        return $user;
    }

    private function getUserRepository()
    {
        $repo = $this->dm->getRepository(User::class);

        return $repo;
    }

    private function getEmailInvitationRepository()
    {
        $repo = $this->dm->getRepository(EmailInvitation::class);

        return $repo;
    }
}
