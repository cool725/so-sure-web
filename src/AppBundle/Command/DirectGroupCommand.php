<?php

namespace AppBundle\Command;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Service\DaviesService;
use AppBundle\Service\DirectGroupService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\DaviesHandlerClaim;

class DirectGroupCommand extends ContainerAwareCommand
{
    /** @var DirectGroupService */
    protected $directGroupService;

    public function __construct(DirectGroupService $directGroupService)
    {
        parent::__construct();
        $this->directGroupService = $directGroupService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:directgroup')
            ->setDescription('Import direct group claims from sftp')
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
                'skip-cleanup',
                null,
                InputOption::VALUE_NONE,
                'Avoid moving sftp file and deleting files after download'
            )
            ->addOption(
                'sheetName',
                null,
                InputOption::VALUE_REQUIRED,
                json_encode(DirectGroupHandlerClaim::$sheetNames)
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
        $skipCleanup = true === $input->getOption('skip-cleanup');
        $file = $input->getOption('file');
        $maxParseErrors = $input->getOption('max-parse-errors');
        $sheetName = $input->getOption('sheetName');
        if (!$sheetName) {
            $sheetName = DirectGroupHandlerClaim::SHEET_NAME_V1;
        }
        if (!in_array($sheetName, DirectGroupHandlerClaim::$sheetNames)) {
            throw new \Exception(sprintf(
                'sheetName must be a valid option %s',
                json_encode(DirectGroupHandlerClaim::$sheetNames)
            ));
        }
        if ($isDaily) {
            $count = $this->directGroupService->claimsDailyEmail();
            $output->writeln(sprintf('%d outstanding claims. Email report sent.', $count));
        } elseif ($file) {
            $lines = $this->directGroupService->importFile($file, $sheetName, $useMime, $maxParseErrors);
            $output->writeln(implode(PHP_EOL, $lines));
        } else {
            $lines = $this->directGroupService->import($sheetName, $useMime, $maxParseErrors, $skipCleanup);
            $output->writeln(implode(PHP_EOL, $lines));
        }
        $output->writeln('Finished');
    }
}
