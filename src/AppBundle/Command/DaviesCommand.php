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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $davies = $this->getContainer()->get('app.davies');
        $lines = $davies->import();
        $output->writeln(implode(PHP_EOL, $lines));
        $output->writeln('Finished');
    }
}
