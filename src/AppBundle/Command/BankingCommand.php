<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\HttpFoundation\File\File;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Classes\SoSure;
use AppBundle\Service\LloydsService;
use AppBundle\Document\File\LloydsFile;

class BankingCommand extends ContainerAwareCommand
{

    /** @var DocumentManager  */
    protected $dm;

    /** @var LloydsService */
    protected $lloydsService;

    public function __construct(DocumentManager $dm, LloydsService $lloydsService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->lloydsService = $lloydsService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:banking:import')
            ->setDescription('Import reports for banking')
            ->addOption(
                'lloyds',
                null,
                InputOption::VALUE_REQUIRED,
                'Import the specified Lloyds report'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lloyds = $input->getOption('lloyds');
        if (!empty($lloyds)) {
            $lloydsFile = new LloydsFile();
            $lloydsFile->setBucket(SoSure::S3_BUCKET_ADMIN);
            $lloydsFile->setKeyFormat($this->getContainer()->getParameter('kernel.environment') . '/%s');
            $lloydsFile->setFile(new File($lloyds));

            $data = $this->lloydsService->processCsv($lloydsFile);

            $this->dm->persist($lloydsFile);
            $this->dm->flush();
        }
        $output->writeln('');
    }
}
