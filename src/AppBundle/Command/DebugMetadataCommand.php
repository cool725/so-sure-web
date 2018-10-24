<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DebugMetadataCommand extends ContainerAwareCommand
{
    /** @var ValidatorInterface */
    protected $validator;

    public function __construct(ValidatorInterface $validator)
    {
        parent::__construct();
        $this->validator = $validator;
    }

    protected function configure()
    {
        $this
            ->setName('metadata:debug')
            ->setDescription('Show metadata')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'class name'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $metadata = $this->validator->getMetadataFor($name);
        print_r($metadata);
    }
}
