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
                'unsubscribes',
                null,
                InputOption::VALUE_NONE,
                'Check for unsubscriptions'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $clear = true === $input->getOption('clear');
        $show = true === $input->getOption('show');
        $process = $input->getOption('process');
        $email = $input->getOption('email');
        $requeue = $input->getOption('requeue');
        $convertLead = $input->getOption('convert-lead');
        $undelete = true === $input->getOption('undelete');
        $unsubscribes = true === $input->getOption('unsubscribes');

        $intercom = $this->getContainer()->get('app.intercom');

        if ($email) {
            $user = $this->getUser($email);

            if ($requeue) {
                $resp = $intercom->queue($user);
                $output->writeln(sprintf('User %s was requeued', $user->getId()));
            } else {
                $resp = $intercom->update($user, true, $undelete);
                $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
            }
        } elseif ($convertLead) {
            $user = $this->getUser($convertLead);
            $resp = $intercom->convertLead($user);
            $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        } elseif ($clear) {
            $intercom->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } elseif ($show) {
            $data = $intercom->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } elseif ($requeue) {
            $count = 0;
            foreach ($this->getAllUsers() as $user) {
                $intercom->queue($user);
                $count++;
            }
            $output->writeln(sprintf("Queued %d Users", $count));
        } elseif ($unsubscribes) {
            $output->writeln(implode(PHP_EOL, $intercom->unsubscribes()));
            $output->writeln(sprintf("Rechecked unsubscribes"));
        } else {
            $count = $intercom->process($process);
            $output->writeln(sprintf("Sent %s updates", $count));
        }
    }

    private function getAllUsers()
    {
        $repo = $this->getUserRepository();

        return $repo->findAll();
    }

    private function getUser($email)
    {
        $repo = $this->getUserRepository();
        $user = $repo->findOneBy(['emailCanonical' => strtolower($email)]);
        if (!$user) {
            throw new \Exception('unable to find user');
        }

        return $user;
    }

    private function getUserRepository()
    {
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(User::class);

        return $repo;
    }
}
