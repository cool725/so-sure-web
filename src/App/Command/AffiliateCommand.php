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
        $this->setDescription('Commit affiliate users who have been in long enough to charge for.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->affiliateService->generate();
    }
}
