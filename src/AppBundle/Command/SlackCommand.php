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
            ->addOption(
                'weeks',
                null,
                InputOption::VALUE_REQUIRED,
                'Run for # of weeks instead of current date',
                null
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $channel = $input->getOption('channel');
        $weeks = $input->getOption('weeks');

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(PhonePolicy::class);

        $start = new \DateTime('2017-01-02');
        $initial = 86;
        $weekOffset = 17;

        if (!$weeks) {
            $now = new \DateTime();
            $weeks = floor($now->diff($start)->days / 7);
        } else {
            $weeks = $weeks - $weekOffset;
        }

        $target = $initial;
        for ($i = 1; $i <= $weeks; $i++) {
            $target = round(1.1 * $target);
        }
        // This is what its supposed to be, but due to excel and rounding each week, we've deviated
        // $target = round($initial * pow(1.1, $weeks));

        $yesterday = new \DateTime();
        $yesterday->sub(new \DateInterval('P1D'));
        $total = $repo->countAllActivePolicies();
        $daily = $total - $repo->countAllActivePolicies($yesterday);

        $text = sprintf(
            'Week %d - Target: %d Actual: %d Remaining: %d Last 24 hours: %d',
            $weeks + $weekOffset,
            $target,
            $total,
            $target - $total,
            $daily
        );

        $slack = $this->getContainer()->get('nexy_slack.client');
        $message = $slack->createMessage();
        $message
            ->to($channel)
            ->setText($text)
        ;
        $slack->sendMessage($message);
    }
}
