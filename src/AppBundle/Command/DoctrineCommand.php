<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class DoctrineCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:doctrine:index')
            ->setDescription('Ensure Indexes are present')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $dm->getSchemaManager()->ensureIndexes();

        $censusDm = $this->getContainer()->get('doctrine_mongodb.odm.census_document_manager');
        $censusDm->getSchemaManager()->ensureIndexes();
    }
}
