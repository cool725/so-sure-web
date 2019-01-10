<?php

namespace AppBundle\Command;

use AppBundle\Document\Lead;
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
                'convert-lead-by-email',
                null,
                InputOption::VALUE_NONE,
                'Force a lead to user conversion'
            )
            ->addOption(
                'convert-lead-by-id',
                null,
                InputOption::VALUE_NONE,
                'Force a lead to user conversion'
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
            ->addOption(
                'reset-user-id',
                null,
                InputOption::VALUE_NONE,
                'Assign a new intercom user id for a user/lead. Required email.'
            )
            ->addOption(
                'reset-intercom-id',
                null,
                InputOption::VALUE_NONE,
                'Reset the intercom id for a user/lead. Required email.'
            )
            ->addOption(
                'destroy',
                null,
                InputOption::VALUE_NONE,
                'Delete all intercom records & create new ids. Required email.'
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
        $convertLeadByEmail = true === $input->getOption('convert-lead-by-email');
        $convertLeadById = true === $input->getOption('convert-lead-by-id');
        $undelete = true === $input->getOption('undelete');
        $maintenance = true === $input->getOption('maintenance');
        $leadMaintenance = true === $input->getOption('lead-maintenance');
        $userMaintenance = true === $input->getOption('user-maintenance');
        $pendingInvites = true === $input->getOption('pending-invites');
        $countQueue = true === $input->getOption('count-queue');
        $update = true === $input->getOption('update');
        $resetUserId = true === $input->getOption('reset-user-id');
        $resetIntercomId = true === $input->getOption('reset-intercom-id');
        $destroy = true === $input->getOption('destroy');

        if ($email) {
            $user = $this->getUser($email);
            $lead = $this->getLead($email);

            if ($requeue) {
                if (!$user) {
                    throw new \Exception('Unable to find user for email address');
                }
                $this->intercom->queue($user);
                $output->writeln(sprintf('User %s was requeued.', $user->getId()));
            } elseif ($convertLeadByEmail || $convertLeadById) {
                if (!$user) {
                    throw new \Exception('Unable to find user for email address');
                }
                $resp = $this->intercom->convertLead($user, $convertLeadById);
                $output->writeln(sprintf('Lead %s was converted. Response:', $user->getId()));
                $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
            } elseif ($update) {
                if (!$user) {
                    throw new \Exception('Unable to find user for email address');
                }
                $resp = $this->intercom->update($user);
                $output->writeln(sprintf('User %s was updated. Response:', $user->getId()));
                $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
            } elseif ($resetUserId) {
                if ($user) {
                    $this->intercom->resetIntercomUserId($user);
                    $output->writeln(sprintf('User %s has a new intercom user id.', $user->getId()));
                }
                if ($lead) {
                    $this->intercom->resetIntercomUserIdForLead($lead);
                    $output->writeln(sprintf('Lead %s has a new intercom user id.', $lead->getId()));
                }
            } elseif ($destroy) {
                if ($user) {
                    $this->intercom->destroy($user);
                    $output->writeln(sprintf('User %s was deleted & has a new intercom user id.', $user->getId()));
                }
                if ($lead) {
                    $this->intercom->destroyLead($lead);
                    $output->writeln(sprintf('Lead %s was deleted & has a new intercom user id.', $lead->getId()));
                }
            } else {
                if (!$user) {
                    throw new \Exception('Unable to find user for email address');
                }

                if ($user->getIntercomId()) {
                    $resp = $this->intercom->getIntercomUser($user);
                } else {
                    $resp = $this->intercom->getIntercomUser($user, false);
                }
                $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
            }

            if ($resetIntercomId) {
                if ($user) {
                    $this->intercom->resetIntercomId($user);
                    $output->writeln(sprintf('User %s no longer has an associated intercom id.', $user->getId()));
                }
                if ($lead) {
                    $this->intercom->resetIntercomIdForLead($lead);
                    $output->writeln(sprintf('Lead %s no longer has an associated intercom id.', $lead->getId()));
                }
            }
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

    /**
     * @param string $email
     * @return User
     */
    private function getUser($email)
    {
        $repo = $this->getUserRepository();
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $user;
    }

    /**
     * @param string $email
     * @return Lead
     */
    private function getLead($email)
    {
        $repo = $this->getLeadRepository();
        /** @var Lead $lead */
        $lead = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        return $lead;
    }

    private function getUserRepository()
    {
        $repo = $this->dm->getRepository(User::class);

        return $repo;
    }

    private function getLeadRepository()
    {
        $repo = $this->dm->getRepository(Lead::class);

        return $repo;
    }

    private function getEmailInvitationRepository()
    {
        $repo = $this->dm->getRepository(EmailInvitation::class);

        return $repo;
    }
}
