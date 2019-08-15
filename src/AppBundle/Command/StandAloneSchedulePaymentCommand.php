<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Service\BacsService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StandAloneSchedulePaymentCommand extends ContainerAwareCommand
{
    use DateTrait;
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
        $this->setName('sosure:regenerate:schedules:sa')
            ->setDescription("Regenerate Scheduled Payments for policies.")
            ->addOption(
                'policy-id',
                'p',
                InputOption::VALUE_REQUIRED,
                'If you have a specific policy to regenerate, give the long ID'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                "See output without saving"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyId = $input->getOption('policy-id');
        $dryRun = $input->getOption('dry-run');

        $qb = $this->dm->createQueryBuilder(Policy::class)
            ->field('_id')->equals(new \MongoId($policyId));
        $policies = $qb->getQuery()->execute();
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            $policy->cancelScheduledPayments();
            $now = new \DateTime();
            $start = $policy->getStart();
            $end = $policy->getStaticEnd();
            $billingStart = $policy->getBilling();
            if (!$billingStart) {
                $billingStart = $start;
            }
            $billingDay = $billingStart->format('d');
            if ($dryRun) {
                $output->writeln(sprintf(
                    "Policy %s has billing day %s",
                    $policy->getId(),
                    $billingDay
                ));
            }
            $nextPaymentDate = new \DateTime(
                sprintf(
                    "%s-%s-%s",
                    $now->format('Y'),
                    $now->format('m'),
                    $billingDay
                ),
                SoSure::getSoSureTimezone()
            );
            $nextPaymentDate->setTime(3, 0);
            $oneMonth = new \DateInterval("P1M");
            if ($nextPaymentDate < $now) {
                $nextPaymentDate->add($oneMonth);
            }
            while ($nextPaymentDate < $end) {
                $useDate = clone $nextPaymentDate;
                $payment = new ScheduledPayment();
                $useDate = $payment->adjustDayForBilling($useDate);
                if ($policy->getBacsPaymentMethod() !== null) {
                    $useDate = $this->getCurrentOrNextBusinessDay($useDate);
                }
                $payment->setStatus($payment::STATUS_SCHEDULED);
                $payment->setScheduled($useDate);
                $payment->setAmount($policy->getPremiumInstallmentPrice());
                $payment->setPolicy($policy);
                $payment->setNotes("Regenerating intentionally");
                $output->writeln(sprintf(
                    "Generated payment on policy for %s",
                    $useDate->format('Y-m-d H:i:s')
                ));
                if (!$dryRun) {
                    $policy->addScheduledPayment($payment);
                }
                $this->dm->flush();
                $nextPaymentDate->add($oneMonth);
            }
        }
    }
}
