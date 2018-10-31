<?php

namespace AppBundle\Command;

use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Service\SalvaExportService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;
use AppBundle\Document\DateTrait;

class SalvaExportPaymentCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    /** @var SalvaExportService  */
    protected $salvaExportService;

    public function __construct(DocumentManager $dm, SalvaExportService $salvaExportService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->salvaExportService = $salvaExportService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:salva:export:payment')
            ->setDescription('Export all payments to salva')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date'
            )
            ->addOption(
                's3',
                null,
                InputOption::VALUE_NONE,
                'Upload to s3'
            )
            ->addOption(
                'update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Update metadata for the associated s3 file (assumes no --s3 option)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $s3 = true === $input->getOption('s3');
        $updateMetadata = true === $input->getOption('update-metadata');
        $date = $input->getOption('date');
        if ($date) {
            $date = new \DateTime($input->getOption('date'));
        } else {
            $date = $this->startOfPreviousMonth();
            $output->writeln(sprintf('Using last month %s', $date->format('Y-m')));
        }
        $data = [];
        if ($updateMetadata) {
            $dateStart = $this->startOfMonth($date);
            print_r($dateStart);
            $dateEnd = $this->endOfMonth($date);
            print_r($dateEnd);
            $repo = $this->dm->getRepository(SalvaPaymentFile::class);
            /** @var SalvaPaymentFile $paymentFile */
            $paymentFile = $repo->findOneBy(['date' => ['$gte' => $dateStart, '$lt' => $dateEnd]]);
            if ($paymentFile) {
                $data = $this->salvaExportService->exportPayments(true, $date, $paymentFile);
            } else {
                $output->writeln('Failed to find payment file in db');
            }
        } else {
            $data = $this->salvaExportService->exportPayments($s3, $date);
        }
        $output->write(implode(PHP_EOL, $data));
        $output->writeln('');
    }
}
