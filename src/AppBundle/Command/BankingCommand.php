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
use Aws\S3\S3Client;
use AppBundle\Classes\SoSure;
use AppBundle\Service\BankingService;
use AppBundle\Service\LloydsService;
use AppBundle\Document\File\LloydsFile;

class BankingCommand extends ContainerAwareCommand
{

    /** @var DocumentManager  */
    protected $dm;

    /** @var BankingService */
    protected $bankingService;

    /** @var LloydsService */
    protected $lloydsService;

    /** @var S3Client */
    protected $s3;

    public function __construct(DocumentManager $dm, BankingService $bankingService, LloydsService $lloydsService, S3Client $s3)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->bankingService = $bankingService;
        $this->lloydsService = $lloydsService;
        $this->s3 = $s3;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:banking')
            ->setDescription('Import reports for banking or cache data for banking')
            ->addOption(
                'lloyds',
                null,
                InputOption::VALUE_REQUIRED,
                'Import the specified Lloyds report'
            )
            ->addOption(
                'cache',
                null,
                InputOption::VALUE_REQUIRED,
                'cache the required tab (salva, cards, or merchant)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lloyds = $input->getOption('lloyds');
        $cache = $input->getOption('cache');
        if (!empty($lloyds)) {
            $lloydsFile = new LloydsFile();
            $lloydsFile->setBucket(SoSure::S3_BUCKET_ADMIN);
            $lloydsFile->setKeyFormat($this->getContainer()->getParameter('kernel.environment') . '/%s');
            $lloydsFile->setFile(new File($lloyds));
            $lloydsFile->setDate(new \DateTime());
            $lloydsFile->setFileName(sprintf('%s.csv', $lloydsFile->getS3FileName()));

            $data = $this->lloydsService->processCsv($lloydsFile);

            $this->dm->persist($lloydsFile);
            $this->dm->flush();

            $result = $this->s3->putObject(array(
                'Bucket' => SoSure::S3_BUCKET_ADMIN,
                'Key' => $lloydsFile->getKey(),
                'SourceFile' => $lloyds,
            ));
        }
        elseif (!empty($cache)) {
            $now = \DateTime::createFromFormat('U', time());
            $year = $now->format('Y');
            $month = $now->format('m');
            $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
            $output->writeln(sprintf('Caching %s for %d-%d', $cache, $year, $month));
            if ($cache === "salva") {
                $this->bankingService->getSoSureBanking($date);
                $this->bankingService->getSalvaBanking($date, $year, $month);
                $this->bankingService->getReconcilationBanking($date);
            }
            elseif ($cache === "cards") {
                $this->bankingService->getJudoBanking($date, $year, $month);
                $this->bankingService->getCheckoutBanking($date, $year, $month);
            }
            elseif ($cache === "merchant") {
                $this->bankingService->getCashflowsBanking($date, $year, $month);
                $this->bankingService->getBarclaysBanking($date, $year, $month);
            }
            $this->bankingService->getLloydsBanking($date, $year, $month);
        }
        $output->writeln('');
    }
}
