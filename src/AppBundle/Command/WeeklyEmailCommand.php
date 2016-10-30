<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Policy;

class WeeklyEmailCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:email:weekly')
            ->setDescription('Weekly Emails')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit the number of emails',
                50
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $limit = $input->getOption('limit');
        $logger = $this->getContainer()->get('logger');
        $policyService = $this->getContainer()->get('app.policy');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policies = $repo->getWeeklyEmail($this->getContainer()->getParameter('kernel.environment'));

        $count = 0;
        foreach ($policies as $policy) {
            try {
                if ($count >= $limit) {
                    continue;
                }
                // $inviteService = $this->getContainer()->get('app.invitation');
                // $inviteService->inviteByEmail($policy, 'patrick@so-sure.com');
                $policyService->weeklyEmail($policy);
                $output->writeln(sprintf('%s', $policy->getUser()->getEmail()));
            } catch (\Exception $e) {
                $logger->error($e->getMessage());
            }
            $dm->flush();
            $count++;
        }
        $output->writeln(sprintf('%d emails sent', $count));
    }
}
