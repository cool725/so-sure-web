<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Claim;

class BaseCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:base');
    }

    protected function getManager()
    {
        return $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }
}
