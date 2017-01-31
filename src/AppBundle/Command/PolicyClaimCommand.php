<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;

class PolicyClaimCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:claim')
            ->setDescription('')
            ->addArgument(
                'policy-id',
                InputArgument::REQUIRED,
                'Policy Id'
            )
            ->addArgument(
                'claim-id',
                InputArgument::REQUIRED,
                'Claim Id'
            )
            ->addOption(
                'imei',
                null,
                InputOption::VALUE_NONE,
                'if set, requires a policy for the imei/serial/claims and will save results against policy'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyId = $input->getArgument('policy-id');
        $claimId = $input->getArgument('claim-id');
        $imei = $input->getOption('imei');
        $imeiService = $this->getContainer()->get('app.imei');

        $policy = $this->getPolicy($policyId);
        $claim = $this->getClaim($claimId);

        if ($imeiService->policyClaim($policy, $claim->getType(), $claim, null, $imei)) {
            print sprintf("Claimscheck %s is good\n", $imei);
        } else {
            print sprintf("Claimscheck %s failed validation\n", $imei);
        }
    }

    private function getPolicy($d)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(PhonePolicy::class);
        $policy = $repo->find($id);

        return $policy;
    }

    private function getClaim($d)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($id);

        return $claim;
    }
}
