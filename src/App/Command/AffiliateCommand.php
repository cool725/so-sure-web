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

    private $affiliateRepository;
    private $affiliateService;

    public function __construct(AffiliateService $affiliateService, DocumentManager $dm)
    {
        parent::__construct();
        $this->affiliateService = $affiliateService;
        $this->affiliateRepository = $dm->getRepository(AffiliateCompany::class);
    }

    protected function configure()
    {
        $this->setDescription('Commit policies and costs that originate from an affiliate and have matured.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $affiliates = $this->affiliateRepository->findAll();
        $charges = count($this->affiliateService->generate($affiliates));
        $output->writeln("{$charges} charges made.");
    }
}
