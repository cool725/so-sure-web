<?php

namespace AppBundle\Command;

use AppBundle\Service\DaviesService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\DaviesHandlerClaim;

class DaviesCommand extends ContainerAwareCommand
{
    /** @var DaviesService  */
    protected $daviesService;

    public function __construct(DaviesService $daviesService)
    {
        parent::__construct();
        $this->daviesService = $daviesService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:davies')
            ->setDescription('Import davies emails from s3')
            ->addOption(
                'daily',
                null,
                InputOption::VALUE_NONE,
                'Run a daily check on outstanding claims'
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Use a local file instead of s3'
            )
            ->addOption(
                'use-extension',
                null,
                InputOption::VALUE_NONE,
                'Use file extension to determine file type instead of mime'
            )
            ->addOption(
                'sheetName',
                null,
                InputOption::VALUE_REQUIRED,
                json_encode(DaviesHandlerClaim::$sheetNames)
            )
            ->addOption(
                'max-parse-errors',
                null,
                InputOption::VALUE_REQUIRED,
                'Max number of errrors',
                10
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isDaily = true === $input->getOption('daily');
        $useMime = true !== $input->getOption('use-extension');
        $file = $input->getOption('file');
        $maxParseErrors = $input->getOption('max-parse-errors');
        $sheetName = $input->getOption('sheetName');
        if (!$sheetName) {
            $sheetName = DaviesHandlerClaim::SHEET_NAME_V6;
        }
        if (!in_array($sheetName, DaviesHandlerClaim::$sheetNames)) {
            throw new \Exception(sprintf(
                'sheetName must be a valid option %s',
                json_encode(DaviesHandlerClaim::$sheetNames)
            ));
        }

        if ($isDaily) {
            $count = $this->daviesService->claimsDailyEmail();
            $output->writeln(sprintf('%d outstanding claims. Email report sent.', $count));
        } elseif ($file) {
            $lines = $this->daviesService->importFile($file, $sheetName, $useMime, $maxParseErrors);
            $output->writeln(implode(PHP_EOL, $lines));
        } else {
            $lines = $this->daviesService->import($sheetName, $useMime, $maxParseErrors);
            $output->writeln(implode(PHP_EOL, $lines));
        }
        $output->writeln('Finished');
    }
}
