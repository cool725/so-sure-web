<?php

namespace AppBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;

class NormalisePostcodeCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

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
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findAll();
        $output->writeln(sprintf('Processing %s users', count($users)));
        $totalProcessed = 0;
        $flushCounter = 1;
        $hasBillingAddress = 0;
        foreach ($users as $user) {
            $totalProcessed++;
            if ($user->hasValidBillingDetails()) {
                $flushCounter++;
                $hasBillingAddress++;
                $postcode = $user->getBillingAddress()->getPostcode();
                $user->getBillingAddress()->setPostcode($postcode);
            }
            if ($flushCounter % 1000 == 0) {
                $this->dm->flush();
            }
        }
        if ($flushCounter > 0) {
            $this->dm->flush();
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
