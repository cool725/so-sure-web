<?php

namespace AppBundle\Command;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\DateTrait;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Service\PaymentService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Policy;

class ScheduledPaymentCommand extends ContainerAwareCommand
{
    use DateTrait;
    /** @var DocumentManager  */
    protected $dm;

    /** @var LoggerInterface  */
    protected $logger;

    /** @var PaymentService */
    protected $paymentService;

    public function __construct(DocumentManager $dm, LoggerInterface $logger, PaymentService $paymentService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->logger = $logger;
        $this->paymentService = $paymentService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:scheduled:payment')
            ->setDescription('Run any payments that are scheduled to run')
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'scheduled id'
            )
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Show scheduled payments for a policy'
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Only display payments that should be run'
            )
            ->addOption(
                'allow-multiple-same-day-payment',
                null,
                InputOption::VALUE_NONE,
                'Typically we only allow one payment per day. If id is set, allow force multiple payments on same day'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Pretend its this date'
            )
            ->addOption(
                'end-of-day',
                null,
                InputOption::VALUE_NONE,
                'Last processing of the day (get all payemnt up to end of day)'
            )
            ->addArgument(
                'prefix',
                InputArgument::REQUIRED,
                'Prefix'
            )
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Doctrine\ODM\MongoDB\LockException
     * @throws \Doctrine\ODM\MongoDB\Mapping\MappingException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');
        $policyNumber = $input->getOption('policyNumber');
        $date = $input->getOption('date');
        $show = true === $input->getOption('show');
        $endOfDay = true === $input->getOption('end-of-day');
        $prefix = $input->getArgument('prefix');
        $allowMultipleSameDayPayment = true === $input->getOption('allow-multiple-same-day-payment');
        $scheduledDate = null;
        if ($date) {
            $scheduledDate = new \DateTime($date);
        } elseif ($endOfDay) {
            $scheduledDate = $this->endOfDay($this->now());
        }

        /** @var ScheduledPaymentRepository $repo */
        $repo = $this->dm->getRepository(ScheduledPayment::class);
        if ($id) {
            /** @var ScheduledPayment $scheduledPayment */
            $scheduledPayment = $repo->find($id);
            try {
                $scheduledPayment = $this->paymentService->scheduledPayment(
                    $scheduledPayment,
                    $prefix,
                    $scheduledDate,
                    !$allowMultipleSameDayPayment
                );
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                $output->writeln($e->getMessage());
            }
            $this->displayScheduledPayment($scheduledPayment, $output);
            //\Doctrine\Common\Util\Debug::dump($scheduledPayment);
        } elseif ($policyNumber) {
            /** @var PolicyRepository $policyRepo */
            $policyRepo = $this->dm->getRepository(Policy::class);
            /** @var Policy $policy */
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            foreach ($policy->getScheduledPayments() as $scheduledPayment) {
                /** @var ScheduledPayment $scheduledPayment */
                $this->displayScheduledPayment($scheduledPayment, $output);
            }
        } else {
            $scheduledPayments = $this->paymentService->getAllValidScheduledPaymentsForTypes(
                $prefix,
                [CheckoutPaymentMethod::class],
                $scheduledDate
            );
            foreach ($scheduledPayments as $scheduledPayment) {
                /** @var ScheduledPayment $scheduledPayment */
                try {
                    if (!$show) {
                        $scheduledPayment = $this->paymentService->scheduledPayment(
                            $scheduledPayment,
                            $prefix,
                            $scheduledDate
                        );
                    }
                    $this->displayScheduledPayment($scheduledPayment, $output);
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                    $output->writeln($e->getMessage());
                    $this->displayScheduledPayment($scheduledPayment, $output);
                }
            }
        }
    }

    protected function displayScheduledPayment(ScheduledPayment $scheduledPayment, OutputInterface $output)
    {
        $output->writeln(sprintf(
            'Policy %s Status %s SId %s Scheduled %s Amount %s Status %s Paid %s',
            $scheduledPayment->getPolicy()->getPolicyNumber(),
            $scheduledPayment->getStatus(),
            $scheduledPayment->getId(),
            $scheduledPayment->getScheduled() ? $scheduledPayment->getScheduled()->format(\DateTime::ATOM) : '?',
            $scheduledPayment->getAmount(),
            $scheduledPayment->getPayment() ? $scheduledPayment->getPayment()->getResult() : 'n/a',
            $scheduledPayment->getPayment() ? $scheduledPayment->getPayment()->getAmount() : '-'
        ));
    }
}
