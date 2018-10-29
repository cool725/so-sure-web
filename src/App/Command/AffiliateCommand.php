<?php

namespace App\Command;

use AppBundle\Service\AffiliateService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AffiliateCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:admin:affiliate';
    protected static $defaultName = self::SERVICE_NAME;

    /** @var AffiliateService */
    private $affiliateService;

    public function __construct(AffiliateService $affiliateService)
    {
        parent::__construct();
        $this->affiliateService = $affiliateService;
    }

    protected function configure()
    {
        $this->setDescription('Commit policies and costs that originate from an affiliate and have matured.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $charges = count($this->affiliateService->generate());
        $output->writeln("{$charges} charges made.");
    }
}
