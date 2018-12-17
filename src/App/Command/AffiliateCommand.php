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

class AffiliateCommand extends ContainerAwareCommand
{
    use DateTrait;
    const SERVICE_NAME = 'sosure:admin:affiliate';
    protected static $defaultName = self::SERVICE_NAME;

    private $affiliateService;

    /**
     * Sets up the AffiliateCommand with it's dependencies.
     * @param AffiliateService $affiliateService is the affiliate service.
     */
    public function __construct(AffiliateService $affiliateService)
    {
        parent::__construct();
        $this->affiliateService = $affiliateService;
    }

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
