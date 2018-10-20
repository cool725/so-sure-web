<?php

namespace AppBundle\Command;

use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Service\BaseImeiService;
use AppBundle\Service\ReceperioService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;

class PolicyClaimCommand extends ContainerAwareCommand
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
                InputOption::VALUE_REQUIRED,
                'if set, requires a policy for the imei/serial/claims and will save results against policy'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyId = $input->getArgument('policy-id');
        $claimId = $input->getArgument('claim-id');
        $imei = $input->getOption('imei');
        /** @var ReceperioService $imeiService */
        $imeiService = $this->getContainer()->get('app.imei');

        $policy = $this->getPolicy($policyId);
        if (!$policy) {
            throw new \Exception('Unable to find policy');
        }
        $claim = $this->getClaim($claimId);
        if (!$claim) {
            throw new \Exception('Unable to find claim');
        }

        if ($imeiService->policyClaim($policy, $claim->getType(), $claim, null, $imei)) {
            print sprintf("Claimscheck %s is good\n", $imei);
        } else {
            print sprintf("Claimscheck %s failed validation\n", $imei);
        }
    }

    private function getPolicy($id)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policy = $repo->find($id);

        return $policy;
    }

    private function getClaim($id)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claim = $repo->find($id);

        return $claim;
    }
}
