<?php

namespace AppBundle\Command;

use AppBundle\Classes\Helvetia;
use AppBundle\Helpers\NumberHelper;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use AppBundle\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Commandline interface to fix Helvetia Commission issues programmatically.
 */
class HelvetiaCommissionCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:helvetia:commission';

    protected static $defaultName = self::SERVICE_NAME;

    /** @var DocumentManager $dm */
    private $dm;

    /**
     * Inserts the dependencies.
     * @param DocumentManager $dm is the document manager that the command will use to query the db.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setDescription('Detects and can also fix incorrect commission on Helvetia Payments')
            ->addOption(
                'month',
                null,
                InputOption::VALUE_REQUIRED,
                'A specific month to look at commission errors in. Format: \'YYYY-MM\'',
                null
            )
            ->addOption(
                'wet',
                null,
                InputOption::VALUE_NONE,
                'Automatically fix detected issues when possible',
                null
            )
            ->addOption(
                'tolerance',
                null,
                InputOption::VALUE_REQUIRED,
                'Tolerance within to allow differences of commission calculations',
                0.005
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tolerance = $input->getOption('tolerance');
        $wet = $input->getOption('wet') == true;
        $monthString = $input->getOption('month');
        $month = $monthString ? \DateTime::createFromFormat('Y-m', $monthString) : null;
        $start = null;
        $end = null;
        if ($month) {
            $start = DateTrait::startOfMonth($month);
            $end = DateTrait::endOfMonth($month);
        }
        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = $this->dm->getRepository(Payment::class);
        $helvetiaPayments = $paymentRepo->getAllPaymentsForPolicyType('helvetia-phone', $start, $end);
        $fine = 0;
        $bad = 0;
        /** @var Payment $payment */
        foreach ($helvetiaPayments as $payment) {
            $policy = $payment->getPolicy();
            $amount = $payment->getAmount();
            $totalCommission = $payment->getTotalCommission();
            $brokerCommission = $payment->getBrokerCommission();
            $coverholderCommission = $payment->getCoverholderCommission();
            $n = $payment->getPolicy()->getPremium()->fractionOfMonthlyPayments($amount);
            $expectedTotalCommission = $payment->getAmount() * Helvetia::COMMISSION_PROPORTION;
            $expectedBrokerCommission = $n * Helvetia::MONTHLY_BROKER_COMMISSION;
            $expectedCoverholderCommission = $expectedTotalCommission - $expectedBrokerCommission;
            if (NumberHelper::equalTo($totalCommission, $expectedTotalCommission, $tolerance) &&
                NumberHelper::equalTo($brokerCommission, $expectedBrokerCommission, $tolerance) &&
                NumberHelper::equalTo($coverholderCommission, $expectedCoverholderCommission, $tolerance)
            ) {
                $fine++;
            } else {
                $bad++;
                $output->writeln(sprintf(
                    '%s: current (b %.2f + c %.2f == %.2f) != expected (b %2.f + c %2.f == %2.f)',
                    $payment->getId(),
                    $brokerCommission,
                    $coverholderCommission,
                    $totalCommission,
                    $expectedBrokerCommission,
                    $expectedCoverholderCommission,
                    $expectedTotalCommission
                ));
                if ($wet) {
                    $payment->setBrokerCommission($expectedBrokerCommission);
                    $payment->setCoverholderCommission($expectedCoverholderCommission);
                    $payment->setTotalCommission($expectedTotalCommission);
                    $this->dm->persist($payment);
                }
            }
        }
        // Say some nice words at the end.
        $output->writeln("{$fine} payments were fine.");
        $output->writeln(sprintf(
            '%d payments were %s.',
            $bad,
            $wet ? 'fixed' : 'fixable'
        ));
        if ($wet) {
            $this->dm->flush();
            $output->writeln("Changes persisted.");
        } else {
            $output->writeln("Dry Mode, no changes persisted.");
        }
    }
}
