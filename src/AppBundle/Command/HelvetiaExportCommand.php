<?php

namespace App\Command;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Service\AffiliateService;
use AppBundle\Document\DateTrait;
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
    use DateTrait;
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
        $this->setDescription('Commit policies and costs that originate from an affiliate and have matured.')
            ->addArgument(
                "date",
                InputArgument::OPTIONAL,
                "date to confirm charges up to. Format: d/m/Y"
            )
            ->setHelp(
                "Used to create charges for affiliate companies based on users attributed to those companies.\n".
                "If a date parameter is provided in the format d/m/Y then only charges due up to the start of\n".
                "that date are created, and charges are never created twice.\n".
                "If not date parameter is provided then the date is considered to be the current day."
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dateString = $input->getArgument('date');
        $date = null;
        if ($dateString) {
            $date = \DateTime::createFromFormat("d/m/Y", $dateString);
            if (!$date) {
                $output->writeln("<error>Invalid date format.</error>");
                return;
            }
        } else {
            $date = $this->startOfDay();
        }
        $charges = count($this->affiliateService->generate(null, $date));
        $output->writeln("{$charges} charges made.");
    }
}
