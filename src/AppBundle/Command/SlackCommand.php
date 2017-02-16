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
            ->addOption(
                'skip-slack',
                null,
                InputOption::VALUE_NONE,
                'Do not post to slack'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $channel = $input->getOption('channel');
        $weeks = $input->getOption('weeks');
        $skipSlack = $input->getOption('skip-slack');

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(PhonePolicy::class);

        $weekText = '';
        $start = new \DateTime('2016-12-05');
        $dowOffset = 0;
        $initial = 70;
        $growth = 9;
        $weekOffset = 17;

        if (!$weeks) {
            $now = new \DateTime();
            $weeks = floor($now->diff($start)->days / 7);
            $dow = $now->diff($start)->days % 7;
            $offset = $dow - $dowOffset >= 0 ? $dow - $dowOffset : (7 - $dowOffset) + $dow;
            $start = clone $now;
            $start = $start->sub(new \DateInterval(sprintf('P%dD', $offset)));
            $end = clone $start;
            $end = $end->add(new \DateInterval('P6D'));
            $weekText = sprintf(
                '%s - %s (Week %d)',
                $start->format('d/m/Y'),
                $end->format('d/m/Y'),
                $weeks + $weekOffset
            );
        } else {
            $weekText = sprintf('Week %d', $weeks);
            $weeks = $weeks - $weekOffset;
        }

        /*
        $target = $initial;
        $growthTarget = $initial;
        for ($i = 1; $i <= $weeks; $i++) {
            $target = round(1.1 * $target);
            $growth = round(1.1 * $growth);
            $growthTarget += $growth;
            //print sprintf("week %d growth %s\n", $i, $growth);
        }
        */
        // This is what its supposed to be
        $target = round($initial * pow(1.1, $weeks));
        $growthTarget = $target;

        $yesterday = new \DateTime();
        $yesterday->sub(new \DateInterval('P1D'));
        $total = $repo->countAllActivePolicies();
        $daily = $total - $repo->countAllActivePolicies($yesterday);

        // @codingStandardsIgnoreStart
        $text = sprintf(
            "*%s*\nTarget: %d\nActual: %d\nRemaining: %d\nLast 24 hours: *%d*\n\n_policy compounding_",
            $weekText,
            $growthTarget,
            $total,
            $target - $total,
            $daily
        );
        // @codingStandardsIgnoreEnd

        $output->writeln($text);
        if ($skipSlack) {
            return;
        }

        $slack = $this->getContainer()->get('nexy_slack.client');
        $message = $slack->createMessage();
        $message
            ->to($channel)
            ->setText($text)
        ;
        $slack->sendMessage($message);
    }
}
