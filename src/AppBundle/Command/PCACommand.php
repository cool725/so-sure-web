<?php

namespace AppBundle\Command;

use AppBundle\Service\PCAService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class PCACommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:pca:bankaccount')
            ->setDescription('Query bank account from the PCA provider and output the response.')
            ->addArgument(
                'sortcode',
                InputArgument::REQUIRED,
                'sortcode'
            )
            ->addArgument(
                'account',
                InputArgument::REQUIRED,
                'account'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sortcode = $input->getArgument('sortcode');
        $accountNumber = $input->getArgument('account');
        /** @var PCAService $pca */
        $pca = $this->getContainer()->get('app.address');
        $data = $pca->findBankAccountRequest($sortcode, $accountNumber);
        $output->writeln(json_encode($data));
    }
}
