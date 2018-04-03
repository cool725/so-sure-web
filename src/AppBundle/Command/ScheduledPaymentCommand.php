<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
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
                'Pretent its this date'
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
        $prefix = $input->getArgument('prefix');
        $allowMultipleSameDayPayment = true === $input->getOption('allow-multiple-same-day-payment');
        $scheduledDate = null;
        if ($date) {
            $scheduledDate = new \DateTime($date);
        }

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $logger = $this->getContainer()->get('logger');
        $paymentService = $this->getContainer()->get('app.payment');
        /** @var ScheduledPaymentRepository $repo */
        $repo = $dm->getRepository(ScheduledPayment::class);
        if ($id) {
            $scheduledPayment = $repo->find($id);
            $scheduledPayment = $paymentService->scheduledPayment(
                $scheduledPayment,
                $prefix,
                $scheduledDate,
                !$allowMultipleSameDayPayment
            );
            $this->displayScheduledPayment($scheduledPayment, $output);
            //\Doctrine\Common\Util\Debug::dump($scheduledPayment);
        } elseif ($policyNumber) {
            /** @var PolicyRepository $policyRepo */
            $policyRepo = $dm->getRepository(Policy::class);
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            foreach ($policy->getScheduledPayments() as $scheduledPayment) {
                $this->displayScheduledPayment($scheduledPayment, $output);
            }
        } else {
            $scheduledPayments = $paymentService->getAllValidScheduledPaymentsForType(
                $prefix,
                JudoPaymentMethod::class
            );
            foreach ($scheduledPayments as $scheduledPayment) {
                /** @var ScheduledPayment $scheduledPayment */
                try {
                    if (!$show) {
                        $scheduledPayment = $paymentService->scheduledPayment($scheduledPayment, $prefix);
                    }
                    $this->displayScheduledPayment($scheduledPayment, $output);
                } catch (\Exception $e) {
                    $logger->error($e->getMessage());
                    $output->writeln($e->getMessage());
                    $this->displayScheduledPayment($scheduledPayment, $output);
                }
            }
        }
    }

    protected function displayScheduledPayment(ScheduledPayment $scheduledPayment, $output)
    {
        $output->writeln(sprintf(
            'Policy %s Status %s SId %s Scheduled %s Amount %s Status %s Paid %s',
            $scheduledPayment->getPolicy()->getPolicyNumber(),
            $scheduledPayment->getStatus(),
            $scheduledPayment->getId(),
            $scheduledPayment->getScheduled()->format(\DateTime::ATOM),
            $scheduledPayment->getAmount(),
            $scheduledPayment->getPayment() ? $scheduledPayment->getPayment()->getResult() : 'n/a',
            $scheduledPayment->getPayment() ? $scheduledPayment->getPayment()->getAmount() : '-'
        ));
    }
}
