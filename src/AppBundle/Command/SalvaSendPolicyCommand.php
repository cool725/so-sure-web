<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\PhonePolicy;

class SalvaSendPolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:send-policy')
            ->setDescription('Export a policies to salva')
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'policyNumber'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyNumber = $input->getOption('policyNumber');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(PhonePolicy::class);
        $phonePolicy = $repo->findOneBy(['policyNumber' => $policyNumber]);
        $salva = $this->getContainer()->get('app.salva');
        $salva->sendPolicy($phonePolicy);
    }
}
