<?php

namespace AppBundle\Command;

use AppBundle\Document\Feature;
use AppBundle\Service\FeatureService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;

class FeatureCommand extends ContainerAwareCommand
{
    /** @var FeatureService */
    protected $featureService;

    public function __construct(FeatureService $featureService)
    {
        parent::__construct();
        $this->featureService = $featureService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:feature')
            ->setDescription('Update so-sure feature')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Feature name'
            )
            ->addArgument(
                'enabled',
                InputArgument::REQUIRED,
                'true for enabled'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $enabled = null;
        // FILTER_NULL_ON_FAILURE  doesn't seem to work as expected, so just check for null first
        if ($input->getArgument('enabled') !== null) {
            $enabled = filter_var($input->getArgument('enabled'), FILTER_VALIDATE_BOOLEAN);
        }

        $this->featureService->setEnabled($name, $enabled);
        $output->writeln(sprintf('%s %s feature flag', $enabled ? 'Enabled' : 'Disabled', $name));
    }
}
