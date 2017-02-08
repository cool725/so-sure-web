<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class MixpanelCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:mixpanel')
            ->setDescription('Run mixpanel')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'delete|test|clear|show (or blank for process)'
            )
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $email = $input->getOption('email');
        $id = $input->getOption('id');
        $process = $input->getOption('process');
        $user = null;
        if ($email) {
            $user = $this->getUser($email);
            $id = $user->getId();
        }
        if ($action == 'test') {
            $results = $this->getMixpanel()->queueTrack("button clicked", array("label" => "sign-up"), $user);
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
        } elseif ($action == 'delete') {
            if (!$id) {
                throw new \Exception('email or id is required');
            }
            $results = $this->getMixpanel()->delete($id);
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
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
            $count = $this->getMixpanel()->process($process);
            $output->writeln(sprintf("Sent %s updates", $count));
        }
    }
    
    private function getMixpanel()
    {
        return $this->getContainer()->get('app.mixpanel');
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
