<?php

namespace AppBundle\Command;

use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
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

class ImeiCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var ReceperioService */
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
            ->setName('sosure:imei')
            ->setDescription('Run manual check on imei. Also see sosure:policy:claim')
            ->addArgument(
                'imei',
                InputArgument::REQUIRED,
                'Imei - this is the cheap gsma check of £0.02'
            )
            ->addOption(
                'serial',
                null,
                InputOption::VALUE_REQUIRED,
                'serial - will run make/model check of £0.05'
            )
            ->addOption(
                'claimscheck',
                null,
                InputOption::VALUE_NONE,
                'expensive £0.90 check (implies --save && --register)'
            )
            ->addOption(
                'register',
                null,
                InputOption::VALUE_REQUIRED,
                'true for settled/false for logged; register a phone as belonging to us (implies --save)'
            )
            ->addOption(
                'claim-id',
                null,
                InputOption::VALUE_REQUIRED,
                'use claim for register/claimscheck'
            )
            ->addOption(
                'claim-number',
                null,
                InputOption::VALUE_REQUIRED,
                'use claim for register/claimscheck'
            )
            ->addOption(
                'policy-id',
                null,
                InputOption::VALUE_REQUIRED,
                'for replaced imei cases, use policy id'
            )
            ->addOption(
                'device',
                null,
                InputOption::VALUE_REQUIRED,
                'device'
            )
            ->addOption(
                'memory',
                null,
                InputOption::VALUE_REQUIRED,
                'memory'
            )
            ->addOption(
                'save',
                null,
                InputOption::VALUE_NONE,
                'if set, requires a policy for the imei/serial/claims and will save results against policy'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $imei = $input->getArgument('imei');
        /** @var string $serial **/
        $serial = $input->getOption('serial');
        $device = $input->getOption('device');
        $memory = $input->getOption('memory');
        $claimId = $input->getOption('claim-id');
        $claimNumber = $input->getOption('claim-number');
        $policyId = $input->getOption('policy-id');
        $claimscheck = $input->getOption('claimscheck');
        $register = null;
        // FILTER_NULL_ON_FAILURE  doesn't seem to work as expected, so just check for null first
        if ($input->getOption('register') !== null) {
            $register = filter_var($input->getOption('register'), FILTER_VALIDATE_BOOLEAN);
        }
        $save = true === $input->getOption('save');
        $phone = $this->getPhone($device, $memory);

        $policy = null;
        if ($policyId) {
            $policy = $this->getPolicy($policyId);
        } elseif ($register !== null || $claimscheck) {
            $policy = $this->getPolicyByImei($imei);
        }
        $claim = null;
        if ($claimId) {
            $claim = $this->getClaim($claimId);
        } elseif ($claimNumber) {
            $claim = $this->getClaimByNumber($claimNumber);
        }

        if ($register !== null) {
            if ($this->imeiService->registerClaims($policy, $claim, $imei, $register)) {
                print sprintf("Register claim for imei %s is good\n", $imei);
            } else {
                print sprintf("Register claim for imei %s failed\n", $imei);
            }
        } elseif ($claimscheck) {
            if ($this->imeiService->checkClaims($policy, $claim, $imei, null)) {
                print sprintf("Claimscheck for imei %s is good\n", $imei);
            } else {
                print sprintf("Claimscheck for imei %s failed validation\n", $imei);
            }
        } else {
            if ($save) {
                if ($this->imeiService->reprocessImei($phone, $imei)) {
                    print sprintf("Imei %s is good\n", $imei);
                } else {
                    print sprintf("Imei %s failed validation\n", $imei);
                }
            } else {
                if ($this->imeiService->checkImei($phone, $imei)) {
                    print sprintf("Imei %s is good\n", $imei);
                } else {
                    print sprintf("Imei %s failed validation\n", $imei);
                }
            }
        }

        if ($serial) {
            if ($save) {
                if ($this->imeiService->reprocessSerial($phone, $serial)) {
                    print sprintf("Serial %s is good\n", $serial);
                } else {
                    print sprintf("Serial %s failed validation\n", $serial);
                }
            } else {
                if ($this->imeiService->checkSerial($phone, $serial, $imei)) {
                    print sprintf("Serial %s is good\n", $serial);
                } else {
                    print sprintf("Serial %s failed validation\n", $serial);
                }
            }
        }
    }

    private function getPolicyByImei($imei)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policy = $repo->findOneBy(['imei' => $imei]);
        if (!$policy) {
            throw new \Exception('Unable to find policy');
        }

        return $policy;
    }

    private function getPolicy($policyId)
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policy = $repo->find($policyId);
        if (!$policy) {
            throw new \Exception('Unable to find policy');
        }

        return $policy;
    }

    private function getClaim($claimId)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claim = $repo->find($claimId);
        if (!$claim) {
            throw new \Exception('Unable to find claim');
        }

        return $claim;
    }

    private function getClaimByNumber($claimNumber)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claim = $repo->findOneBy(['number' => $claimNumber]);
        if (!$claim) {
            throw new \Exception('Unable to find claim');
        }

        return $claim;
    }

    private function getPhone($device, $memory)
    {
        $phone = null;
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $this->dm->getRepository(Phone::class);
        if ($device && $memory) {
            $phone = $phoneRepo->findOneBy(['devices' => $device, 'memory' => (int)$memory]);
        } elseif ($device) {
            $phone = $phoneRepo->findOneBy(['devices' => $device]);
        } else {
            $phones = $phoneRepo->findAll();
            while ($phone == null) {
                $phone = $phones[rand(0, count($phones) - 1)];
                if (!$phone->getCurrentPhonePrice() || $phone->getMake() == "ALL") {
                    $phone = null;
                }
            }
        }

        return $phone;
    }
}
