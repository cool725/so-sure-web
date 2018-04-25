<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Policy;

class MongoCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:mongo')
            ->setDescription('Ensure Indexes are present')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Update action to take. Options: [payer]'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');

        if ($action == 'payer') {
            $updated = 0;
            $skipped = 0;
            $repo = $this->getManager()->getRepository(Policy::class);
            foreach ($repo->findAll() as $policy) {
                if (!$policy->getPayer() && $policy->getUser() && $policy->getPolicyNumber()) {
                    $policy->getUser()->addPayerPolicy($policy);
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            $this->getManager()->flush();
            $output->writeln(sprintf("%d updated %s skipped", $updated, $skipped));
        } else {
            throw new \Exception('Unknown action.  See -h');
        }

        $output->writeln('Finished');
    }
}
