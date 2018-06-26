<?php

namespace AppBundle\Command;

use AppBundle\Service\MixpanelService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;

class MixpanelCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:mixpanel')
            ->setDescription('Run mixpanel')
            // @codingStandardsIgnoreStart
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'delete|delete-old-users|test|clear|show|sync|sync-all|data|attribution|count-users|count-queue (or blank for process)'
            )
            // @codingStandardsIgnoreEnd
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'email address of user'
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'id of mixpanel user'
            )
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Max Number to process',
                50
            )
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days to keep users',
                90
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $email = $input->getOption('email');
        $id = $input->getOption('id');
        $process = $input->getOption('process');
        $days = $input->getOption('days');
        $user = null;
        if ($email) {
            if ($user = $this->getUser($email)) {
                $id = $user->getId();
            }
        }
        if ($action == 'test') {
            if (!$user) {
                throw new \Exception('Requires user; add --email');
            }
            $results = $this->getMixpanel()->queueTrackWithUser($user, "button clicked", array("label" => "sign-up"));
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
        } elseif ($action == 'data') {
            $end = new \DateTime();
            $end->sub(new \DateInterval(sprintf('P%dD', $end->format('N'))));
            $start = clone $end;
            $start->sub(new \DateInterval('P6D'));
            $output->writeln(sprintf('Running from %s to %s', $start->format('Y-m-d'), $end->format('Y-m-d')));
            $results = $this->getMixpanel()->stats($start, $end);
            print_r($results);
            $output->writeln('Finished');
        } elseif ($action == 'attribution') {
            if (!$user) {
                throw new \Exception('Requires user; add --email');
            }
            $results = $this->getMixpanel()->attributionByUser($user);
            $output->writeln(sprintf('Attribution %s', json_encode($results, JSON_PRETTY_PRINT)));
        } elseif ($action == 'sync') {
            if (!$user) {
                throw new \Exception('Requires user; add --email');
            }
            $results = $this->getMixpanel()->updateUser($user);
            $output->writeln('Queued user update');
        } elseif ($action == 'sync-all') {
            $policies = $this->getPhonePolicyRepository()->findAllActiveUnpaidPolicies();
            $count = 0;
            foreach ($policies as $policy) {
                $results = $this->getMixpanel()->updateUser($policy->getUser());
                $count++;
            }
            $output->writeln(sprintf('Queued %d user update', $count));
        } elseif ($action == 'delete') {
            if (!$id) {
                throw new \Exception('email or id is required');
            }
            $results = $this->getMixpanel()->queueDelete($id);
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
        } elseif ($action == 'delete-old-users') {
            $data = $this->getMixpanel()->deleteOldUsers($days);
            $output->writeln(sprintf("Queued %d users for deletion (of %d)", $data['count'], $data['total']));
        } elseif ($action == 'count-users') {
            $total = $this->getMixpanel()->getUserCount();
            $output->writeln(sprintf("%d Users", $total));
        } elseif ($action == 'count-queue') {
            $total = $this->getMixpanel()->countQueue();
            $output->writeln(sprintf("%d in queue", $total));
        } elseif ($action == 'clear') {
            $this->getMixpanel()->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } elseif ($action == 'show') {
            $data = $this->getMixpanel()->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } else {
            $data = $this->getMixpanel()->process($process);
            $output->writeln(sprintf("Processed %d Requeued: %d", $data['processed'], $data['requeued']));
        }
    }

    /**
     * @return MixpanelService
     */
    private function getMixpanel()
    {
        /** @var MixpanelService $mixpanel */
        $mixpanel = $this->getContainer()->get('app.mixpanel');

        return $mixpanel;
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
        $repo = $this->getManager()->getRepository(User::class);

        return $repo;
    }

    private function getPhonePolicyRepository()
    {
        $repo = $this->getManager()->getRepository(PhonePolicy::class);

        return $repo;
    }
}
