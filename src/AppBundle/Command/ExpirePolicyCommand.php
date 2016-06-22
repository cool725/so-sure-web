<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\User;

class ExpirePolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:expire')
            ->setDescription('Expire unpaid policies')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyService = $this->getContainer()->get('app.policy');
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(PhonePolicy::class);

        $policies = $policyRepo->findBy(['status' => PhonePolicy::STATUS_UNPAID]);
        $count = 0;
        foreach ($policies as $policy) {
            if ($policy->shouldExpirePolicy()) {
                $policyService->cancel($policy);
                $output->writeln(sprintf('Cancelled Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
                $count++;
            }
        }

        $output->writeln(sprintf('Finished. %s policies cancelled', $count));
    }
}
