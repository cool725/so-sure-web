<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class BrowserCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:browser')
            ->setDescription('Send an email with any mobile browsers that are not in the db.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $device = $this->getContainer()->get('app.deviceatlas');
        $device->sendAll();
        $output->writeln('Sent');
    }
}
