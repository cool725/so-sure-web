<?php

namespace AppBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class DoctrineCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var DocumentManager */
    protected $censusDm;

    public function __construct(DocumentManager $dm, DocumentManager $censusDm)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->censusDm = $censusDm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:doctrine:index')
            ->setDescription('Ensure Indexes are present')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->dm->getSchemaManager()->ensureIndexes();
        $this->censusDm->getSchemaManager()->ensureIndexes();
    }
}
