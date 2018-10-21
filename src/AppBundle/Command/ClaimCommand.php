<?php

namespace AppBundle\Command;

use AppBundle\Repository\ClaimRepository;
use AppBundle\Service\ClaimsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Claim;

class ClaimCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var ClaimsService */
    protected $claimsService;

    public function __construct(DocumentManager $dm, ClaimsService $claimsService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->claimsService = $claimsService;
    }

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
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->findOneBy(['number' => $claimNumber]);
        if (!$claim) {
            throw new \Exception(sprintf('Unable to find claim %s', $claimNumber));
        }
        if ($this->claimsService->processClaim($claim)) {
            $output->writeln(sprintf('Successfully processed claim %s', $claimNumber));
        } else {
            $output->writeln(sprintf(
                'Unable to process claim %s. Possibly already processed or not settled.',
                $claimNumber
            ));
        }
    }
}
