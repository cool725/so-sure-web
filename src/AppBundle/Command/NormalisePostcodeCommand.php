<?php

namespace AppBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;

class NormalisePostcodeCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:user:normalize-postcode')
            ->setDescription('Normalize Postcodes in the database')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Normalize postcode data for all billing addresses');
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findAll();
        $output->writeln(sprintf('Processing %s users', count($users)));
        $totalProcessed = 0;
        $flushCounter = 0;
        $hasBillingAddress = 0;
        foreach ($users as $user) {
            $totalProcessed++;
            if ($user->hasValidBillingDetails()) {
                $flushCounter++;
                $hasBillingAddress++;
                $postcode = $user->getBillingAddress()->getPostcode();
                $user->getBillingAddress()->setPostcode($postcode);
            }

            if ($flushCounter >= 1000) {
                $repo->getDocumentManager()->flush();
                $flushCounter = 0;
            }
        }
        if ($flushCounter > 0) {
            $repo->getDocumentManager()->flush();
        }
        $output->writeln(
            sprintf(
                'Total: %s ValidBillingAddress: %s',
                $totalProcessed,
                $hasBillingAddress
            )
        );
    }
}
