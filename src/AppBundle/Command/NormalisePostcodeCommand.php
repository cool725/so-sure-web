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
            ->setName('sosure:user:normalise-postcode')
            ->setDescription('Normalize Postcodes in the database')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Resubmitting Postcode data for all billing addresses');
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findAll();
        $output->writeln(sprintf('Processing %s users', count($users)));
        $totalProcessed = 0;
        $hasBillingAddress = 0;
        foreach ($users as $user) {
            $totalProcessed++;
            if ($user->hasValidBillingDetails()) {
                $hasBillingAddress++;
                $postcode = $user->getBillingAddress()->getPostcode();
                $user->getBillingAddress()->setPostcode($postcode);
            } else {
                $invalidAddress = ($user->getBillingAddress() == null) ? 'no address' : 'has invalid billing address';
                $output->writeln(
                    sprintf(
                        'User iD: %s, (%s %s) has %s',
                        $user->getId(),
                        $user->getFirstName(),
                        $user->getLastName(),
                        $invalidAddress
                    )
                );
            }
        }
        $repo->getDocumentManager()->flush();
        $output->writeln(
            sprintf(
                'Total: %s ValidBillingAddress: %s',
                $totalProcessed,
                $hasBillingAddress
            )
        );
    }
}
