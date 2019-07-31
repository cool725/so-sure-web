<?php

namespace AppBundle\Command;

use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Service\BacsService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BacsFixSchedulesCommand extends ContainerAwareCommand
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
        $this->setName('sosure:bacs:fix:schedules')
            ->setDescription("Fix Scheduled Payments for BACs policies.")
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
                'If you have a specific policy to fix, give the long ID'
            )
            ->addOption(
                'billing',
                'b',
                InputOption::VALUE_REQUIRED,
                "annual or monthly"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $status = $input->getOption('status');
        $billing = $input->getOption('billing');
        $dryRun = $input->getOption('dry-run');
        $policyId = $input->getOption('policy-id');

        $qb = $this->dm->createQueryBuilder(Policy::class)
            ->field('paymentMethod.type')->equals('bacs');
        if ($billing) {
            if ($billing == 'annual') {
                $period = 1;
            } elseif ($billing == 'monthly') {
                $period = 12;
            } else {
                throw new InvalidOptionException("-billing can only be monthly or annual");
            }
            $qb->field('premiumInstallments')->equals($period);
        }
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
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            $annual = intval($policy->getYearlyPremiumPrice() * 100);
            $paid = intval($policy->getPremiumPaid() * 100);
            $outstanding = intval($annual - $paid);
            $scheduled = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledAmount = 0;
            /** @var ScheduledPayment $payment */
            foreach ($scheduled as $payment) {
                $scheduledAmount += intval($payment->getAmount()  * 100);
            }
            if ($outstanding == 0) {
                if (count($scheduled) > 0) {
                    $output->writeln(
                        sprintf(
                            "Policy %s is fully paid but has %s payments scheduled.",
                            $policy->getId(),
                            count($scheduled)
                        )
                    );
                    $this->cancelExtraPayments($output, $policy, $scheduled, $dryRun);
                } else {
                    if ($dryRun) {
                        $output->writeln(
                            sprintf(
                                "Policy %s appears all good.",
                                $policy->getId()
                            )
                        );
                    }
                }
            } elseif ($outstanding > 0) {
                $pending = $policy->getPendingBacsPayments();
                $pendingAmount = 0;
                /** @var BacsPayment $payment */
                foreach ($pending as $payment) {
                    $pendingAmount += intval($payment->getAmount() * 100);
                }
                $outstanding -= $pendingAmount;
                if ($outstanding == 0) {
                    $output->writeln(
                        sprintf(
                            "Policy %s will be fully paid after current pending payment %s",
                            $policy->getId(),
                            $pending[0]
                        )
                    );
                    if (count($scheduled) > 0) {
                        $this->cancelExtraPayments($output, $policy, $scheduled, $dryRun);
                    }
                } else {
                    $outstanding = $outstanding - $scheduledAmount;
                    if ($outstanding == 0) {
                        if ($dryRun) {
                            $output->writeln(
                                sprintf(
                                    "Policy %s has the correct schedule for payments.",
                                    $policy->getId()
                                )
                            );
                        }
                    } elseif ($outstanding > 0) {
                        $output->writeln(
                            sprintf(
                                "Policy %s does not have enough scheduled payments to become fully paid",
                                $policy->getId()
                            )
                        );
                    } else {
                        $output->writeln(
                            sprintf(
                                "Policy %s has too many scheduled payments. Has %s but needs %s for remaining %s",
                                $policy->getId(),
                                count($scheduled),
                                ($policy->getYearlyPremiumPrice() - $policy->getPremiumPaid()) /
                                $policy->getPremiumInstallmentPrice(),
                                $outstanding
                            )
                        );
                    }
                }
            } elseif ($outstanding < 0) {
                $output->writeln(
                    sprintf(
                        "Policy %s is over paid by %s. A refund will be required.",
                        $policy->getId(),
                        0-$outstanding
                    )
                );
                $this->cancelExtraPayments($output, $policy, $scheduled, $dryRun);
            } else {
                $output->writeln(
                    sprintf(
                        "Cannot determine schedule status for policy %s. Please check manually. Outstanding is %s",
                        $policy->getId(),
                        $outstanding
                    )
                );
            }
        }
        $this->dm->flush();
    }

    public function cancelExtraPayments(OutputInterface $output, Policy $policy, $scheduled, $dryRun = false)
    {
        /** @var ScheduledPayment $payment */
        foreach ($scheduled as $payment) {
            $output->writeln(
                sprintf(
                    "Cancelling scheduled payment %s on policy %s as policy is fully paid",
                    $payment->getId(),
                    $policy->getId()
                )
            );
            if (!$dryRun) {
                $payment->cancel("Policy is fully paid, cancelling all future payments.");
            }
        }
    }
}
