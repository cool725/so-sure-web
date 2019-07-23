<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Service\BacsService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BacsRegenerateSchedulesCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager
     */
    private $dm;

    /**
     * @var BacsService
     */
    private $bacsService;

    /**
     * @var PolicyService
     */
    private $policyService;

    public function __construct(
        DocumentManager $dm,
        BacsService $bacsService,
        PolicyService $policyService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->bacsService = $bacsService;
        $this->policyService = $policyService;
    }

    protected function configure()
    {
        $this->setName('sosure:bacs:regenerate:schedules')
            ->setDescription("Regenerate Scheduled Payments for BACs policies.")
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                "ALL, CURRENT, ACTIVE, UNPAID"
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                "See output without saving"
            )
            ->addOption(
                'policy-id',
                'p',
                InputOption::VALUE_REQUIRED,
                'If you have a specific policy to regenerate, give the long ID'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $status = $input->getOption('status');
        $dryRun = $input->getOption('dry-run');
        $policyId = $input->getOption('policy-id');

        $qb = $this->dm->createQueryBuilder(Policy::class)
            ->field('paymentMethod.type')->equals('bacs');
        if ($status) {
            switch ($status) {
                case 'CURRENT':
                    $qb->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID]);
                    break;
                case 'ACTIVE':
                    $qb->field('status')->equals(Policy::STATUS_ACTIVE);
                    break;
                case 'UNPAID':
                    $qb->field('status')->equals(Policy::STATUS_UNPAID);
                    break;
                default:
                    break;
            }
        }
        if ($policyId) {
            $qb->field('_id')->equals(new \MongoId($policyId));
        }
        $policies = $qb->getQuery()->execute();
        $dateToUse = new \DateTime();
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            $billing = $policy->getBilling();
            if ($billing) {
                $dateToUse = $billing;
            }
            if ($dryRun) {
                $output->writeln(sprintf(
                    "Policy %s would be regenerated and status is %s",
                    $policy->getId(),
                    $policy->getStatus()
                ));
            } else {
                $output->writeln(sprintf(
                    "Regenerating Scheduled Payments for policy %s",
                    $policy->getId()
                ));
                $this->policyService->regenerateScheduledPayments($policy, $dateToUse);
            }
        }
    }
}
