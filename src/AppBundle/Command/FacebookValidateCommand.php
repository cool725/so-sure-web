<?php

namespace AppBundle\Command;

use AppBundle\Service\FacebookService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class FacebookValidateCommand extends ContainerAwareCommand
{
    /** @var FacebookService */
    protected $facebookService;

    public function __construct(FacebookService $facebookService)
    {
        parent::__construct();
        $this->facebookService = $facebookService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:facebook:validate')
            ->setDescription('validate facebook id/token')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'Facebook id'
            )
            ->addArgument(
                'token',
                InputArgument::REQUIRED,
                'Facebook token'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        $token = $input->getArgument('token');

        $result = $this->facebookService->validateTokenId($id, $token);

        $output->writeln(sprintf('Validated: %s', $result ? 'yes' : 'no'));
    }
}
