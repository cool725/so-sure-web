<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class WeeklyEmailCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:email:weekly')
            ->setDescription('Weekly Emails')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyService = $this->getContainer()->get('app.policy');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policies = $repo->getWeeklyEmail($this->getContainer()->getParamter('kernel.environment'));

        foreach ($policies as $policy) {
            try {
                $policyService->weeklyEmail($policy);
            } catch (\Exception $e) {
                $logger = $this->getContainer()->get('logger');
                $logger->error($e->getMessage());
            }
            $dm->flush();
        }
    }
}
