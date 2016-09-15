<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\PhonePolicy;

class SlackCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:slack')
            ->setDescription('Send message to slack')
            ->addOption(
                'channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Channel to post to',
                '#general'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $channel = $input->getOption('channel');
        
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(PhonePolicy::class);
        $yesterday = new \DateTime();
        $yesterday->sub(new \DateInterval('P1D'));
        $total = $repo->countAllActivePolicies();
        $daily = $total - $repo->countAllActivePolicies($yesterday);

        $text = sprintf('There are %d active policies (+%d last 24 hours)', $total, $daily);

        $slack = $this->getContainer()->get('nexy_slack.client');
        $message = $slack->createMessage();
        $message
            ->to($channel)
            ->setText($text)
        ;
        $slack->sendMessage($message);
    }
}
