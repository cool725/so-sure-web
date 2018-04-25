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

class FacebookCommand extends ContainerAwareCommand
{
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

        /** @var FacebookService $fb */
        $fb = $this->getContainer()->get('app.facebook');
        $result = $fb->validateTokenId($id, $token);

        $output->writeln(sprintf('Validated: %s', $result ? 'yes' : 'no'));
    }
}
