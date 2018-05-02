<?php

namespace AppBundle\Command;

use AppBundle\Service\GenderizeService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Classes\SoSure;

class GenderCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:gender')
            ->setDescription('Run gender')
            ->addOption(
                'threshold',
                null,
                InputOption::VALUE_REQUIRED,
                'threshold proabably required to set gender',
                '0.8'
            )
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of users to process',
                '1'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $process = $input->getOption('process');
        $threshold = $input->getOption('threshold');
        /** @var GenderizeService $gender */
        $gender = $this->getContainer()->get('app.gender');
        $data = $gender->run($process, $threshold);
        $output->write(json_encode($data, JSON_PRETTY_PRINT));
    }
}
