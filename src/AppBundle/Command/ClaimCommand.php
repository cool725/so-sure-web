<?php

namespace AppBundle\Command;

use AppBundle\Service\ClaimsService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Claim;

class ClaimCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:claim')
            ->setDescription('Process a claim (possibly after manually updating the data)')
            ->addArgument(
                'claim-number',
                InputArgument::REQUIRED,
                'claim number to process'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $claimNumber = $input->getArgument('claim-number');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->findOneBy(['number' => $claimNumber]);
        if (!$claim) {
            throw new \Exception(sprintf('Unable to find claim %s', $claimNumber));
        }
        /** @var ClaimsService $claimsService */
        $claimsService = $this->getContainer()->get('app.claims');
        if ($claimsService->processClaim($claim)) {
            $output->writeln(sprintf('Successfully processed claim %s', $claimNumber));
        } else {
            $output->writeln(sprintf(
                'Unable to process claim %s. Possibly already processed or not settled.',
                $claimNumber
            ));
        }
    }

    private function getManager()
    {
        return $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }
}
