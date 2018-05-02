<?php

namespace AppBundle\Command;

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

        /** @var FeatureService $feature */
        $feature = $this->getContainer()->get('app.feature');
        $feature->setEnabled($name, $enabled);
        $output->writeln('Finished');
    }
}
