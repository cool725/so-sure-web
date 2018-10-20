<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
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

    /** @var ReceperioService  */
    protected $imeiService;

    public function __construct(DocumentManager $dm, ReceperioService $imeiService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->imeiService = $imeiService;
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

        $policy = $this->getPolicy($policyId);
        if (!$policy) {
            throw new \Exception('Unable to find policy');
        }
        $claim = $this->getClaim($claimId);
        if (!$claim) {
            throw new \Exception('Unable to find claim');
        }

        if ($this->imeiService->policyClaim($policy, $claim->getType(), $claim, null, $imei)) {
            print sprintf("Claimscheck %s is good\n", $imei);
        } else {
            print sprintf("Claimscheck %s failed validation\n", $imei);
        }
    }

    /**
     * @param mixed $id
     * @return PhonePolicy
     * @throws \Doctrine\ODM\MongoDB\LockException
     * @throws \Doctrine\ODM\MongoDB\Mapping\MappingException
     */
    private function getPolicy($id)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        return $policy;
    }

    /**
     * @param mixed $id
     * @return Claim
     * @throws \Doctrine\ODM\MongoDB\LockException
     * @throws \Doctrine\ODM\MongoDB\Mapping\MappingException
     */
    private function getClaim($id)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->find($id);

        return $claim;
    }
}
