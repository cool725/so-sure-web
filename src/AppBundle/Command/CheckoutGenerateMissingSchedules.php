<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Service\BacsService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use MaxMind\Exception\InvalidInputException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckoutGenerateMissingSchedules extends ContainerAwareCommand
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

    public function configure()
    {
        $this->setName("sosure:checkout:generate:missing:schedules")
            ->setDescription("Finds policies that have no scheduled payments, and creates them.")
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                "See which policies would be affected without generating the schedule"
            )
            ->addOption(
                'policy-id',
                'p',
                InputOption::VALUE_REQUIRED,
                "If you know the policy that needs a schedule, give the ID for it"
            )
            ->addOption(
                'from-date',
                'f',
                InputOption::VALUE_REQUIRED,
                "Limit the search to policies after and including this date"
            )
            ->addOption(
                'to-date',
                't',
                InputOption::VALUE_REQUIRED,
                "Limit the search to policies before and including this date"
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $policyId = $input->getOption('policy-id');
        $fromDate = $input->getOption('from-date');
        $toDate = $input->getOption('to-date');

        if ($fromDate) {
            $fromDate = new \DateTime($fromDate);
        }
        if ($toDate) {
            $toDate = new \DateTime($toDate);
        }
        if (($fromDate && $toDate) && $toDate < $fromDate) {
            /** @var \DateTime $toDateTime **/
            $toDateTime = $toDate;
            /** @var \DateTime $fromDateTime **/
            $fromDateTime = $fromDate;
            throw new InvalidInputException(
                sprintf(
                    "to-date %s should not be before from-date %s but it is.",
                    $toDateTime->format('d-m-Y'),
                    $fromDateTime->format('d-m-Y')
                )
            );
        }

        $qb = $this->dm->createQueryBuilder(Policy::class)
            ->field('paymentMethod.type')->equals('checkout')
            ->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            ->field('premiumInstallments')->equals(12);

        if ($fromDate) {
            $qb->field('created')->gte($fromDate);
        }
        if ($toDate) {
            $qb->field('created')->lte($toDate);
        }
        if ($policyId) {
            $qb->field('_id')->equals(
                new \MongoId($policyId)
            );
        }
        $policies = $qb->getQuery()->execute();

        $affected = [];
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            if (count($policy->getScheduledPayments()) == 0) {
                $affected[] = $policy;
                if ($dryRun) {
                    $output->writeln(
                        sprintf(
                            "Policy %s would have payment scheduled generated",
                            $policy->getId()
                        )
                    );
                } else {
                    $output->writeln(
                        sprintf(
                            "Policy %s will have payments schedule generated",
                            $policy->getId()
                        )
                    );
                }
            }
        }

        if ($dryRun) {
            $output->writeln(
                sprintf(
                    "There are %s policies that have no scheduled payments, please check them and run again wet",
                    count($affected)
                )
            );
            return;
        }

        /** @var Policy $fixer */
        foreach ($affected as $fixer) {
            try {
                $this->policyService->generateScheduledPayments($fixer);
                $output->writeln(
                    sprintf(
                        "Payment scheduled successfully generated for %s",
                        $fixer->getId()
                    )
                );
            } catch (\Exception $e) {
                $output->writeln(
                    sprintf(
                        "Could not generate payment scheduled for policy %s as an exception occurred:\n%s",
                        $fixer->getId(),
                        $e->getMessage()
                    )
                );
            }
        }
    }
}
