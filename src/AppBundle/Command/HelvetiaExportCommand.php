<?php

namespace AppBundle\Command;

use AppBundle\Service\HelvetiaExportService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Commandline interface to helvetia exports.
 */
class HelvetiaExportCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:helvetia:export';

    protected static $defaultName = self::SERVICE_NAME;

    /** @var HelvetiaExportService $helvetiaExportService */
    private $helvetiaExportService;

    /**
     * Inserts the dependencies.
     * @param HelvetiaExportService $helvetiaExportService is used to generate and send the exports.
     */
    public function __construct(HelvetiaExportService $helvetiaExportService)
    {
        parent::__construct();
        $this->helvetiaExportService = $helvetiaExportService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setDescription('Export reports to helvetia')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_OPTIONAL,
                'date to confirm charges up to. Format: d/m/Y',
                null
            )
            ->addArgument(
                'report',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'reports to generate (policy|claim|payment|renewal)'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $actions = $input->getArgument('report');
        $dateString = $input->getOption('date');
        $date = $dateString ? \DateTime::createFromFormat($dateString) : new \DateTime();
        foreach ($actions as $action) {
            switch ($action) {
                case 'policy':
                    $this->helvetiaExportService->uploadPolicies();
                    break;
                case 'claim':
                    $this->helvetiaExportService->uploadClaims();
                    break;
                case 'payment':
                    $this->helvetiaExportService->uploadPayments($date);
                    break;
                case 'renewal':
                    $this->helvetiaExportService->uploadRenewals();
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf(
                        "%s is not a valid report",
                        $action
                    ));
            }
        }
    }
}
