<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class DaviesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:davies')
            ->setDescription('Import davies emails from s3')
            ->addOption(
                'daily',
                null,
                InputOption::VALUE_NONE,
                'Run a daily check on outstanding claims'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isDaily = true === $input->getOption('daily');

        $davies = $this->getContainer()->get('app.davies');
        if ($isDaily) {
            $count = $davies->claimsDailyEmail();
            $output->writeln(sprintf('%d outstanding claims. Email report sent.', $count));
        } else {
            $lines = $davies->import();
            $output->writeln(implode(PHP_EOL, $lines));
        }
        $output->writeln('Finished');
    }
}
