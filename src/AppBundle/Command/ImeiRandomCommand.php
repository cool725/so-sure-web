<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\ImeiTrait;

class ImeiRandomCommand extends ContainerAwareCommand
{
    use ImeiTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:imei:random')
            ->setDescription('Generate a random imei - passes luhn check, but not valid otherwise')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(self::generateRandomImei());
    }
}
