<?php

namespace AppBundle\Command;

use AppBundle\Service\BrightstarService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Brightstar;

class BrightstarCommand extends ContainerAwareCommand
{
    /** @var BrightstarService */
    protected $brightstarService;

    public function __construct(BrightstarService $brightstarService)
    {
        parent::__construct();
        $this->brightstarService = $brightstarService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:brightstar')
            ->setDescription('Import brightstar emails from s3')
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Use a local file instead of s3'
            )
            ->addOption(
                'sheetName',
                null,
                InputOption::VALUE_REQUIRED,
                json_encode(Brightstar::$sheetNames)
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getOption('file');
        $sheetName = $input->getOption('sheetName');
        if (!$sheetName) {
            $sheetName = Brightstar::SHEET_NAME_V1;
        }
        if (!in_array($sheetName, Brightstar::$sheetNames)) {
            throw new \Exception(sprintf(
                'sheetName must be a valid option %s',
                json_encode(Brightstar::$sheetNames)
            ));
        }

        if ($file) {
            $lines = $this->brightstarService->importFile($file, $sheetName);
            $output->writeln(implode(PHP_EOL, $lines));
        } else {
            $lines = $this->brightstarService->import($sheetName);
            $output->writeln(implode(PHP_EOL, $lines));
        }
        $output->writeln('Finished');
    }
}
