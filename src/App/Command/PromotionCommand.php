<?php

namespace App\Command;

use AppBundle\Service\PromotionService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A commmand that allows commandline management of the promotion service and promotions.
 */
class PromotionCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:admin:promotion';
    protected static $defaultName = self::SERVICE_NAME;

    /** @var PromotionService */
    private $promotionService;

    public function __construct(PromotionService $promotionService)
    {
        parent::__construct();
        $this->promotionService = $promotionService;
    }

    protected function configure()
    {
        $this->setDescription('Check which policies should be getting a reward from a promotion.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $rewards = $this->promotionService->generate();
        $output->writeln("{$rewards} rewards were given.");
    }
}
