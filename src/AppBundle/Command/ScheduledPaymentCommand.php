<?php

namespace AppBundle\Command;

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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');
        $policyNumber = $input->getOption('policyNumber');
        $date = $input->getOption('date');
        $show = true === $input->getOption('show');
        $prefix = $input->getArgument('prefix');
        $scheduledDate = null;
        if ($date) {
            $scheduledDate = new \DateTime($date);
        }

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $logger = $this->getContainer()->get('logger');
        $judoPay = $this->getContainer()->get('app.judopay');
        $repo = $dm->getRepository(ScheduledPayment::class);
        if ($id) {
            $scheduledPayment = $repo->find($id);
            $scheduledPayment = $judoPay->scheduledPayment($scheduledPayment, $prefix, $scheduledDate);
            $this->displayScheduledPayment($scheduledPayment, $output);
            //\Doctrine\Common\Util\Debug::dump($scheduledPayment);
        } elseif ($policyNumber) {
            $policyRepo = $dm->getRepository(Policy::class);
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            foreach ($policy->getScheduledPayments() as $scheduledPayment) {
                $this->displayScheduledPayment($scheduledPayment, $output);
            }
        } else {
            $scheduledPayments = $repo->findScheduled();
            foreach ($scheduledPayments as $scheduledPayment) {
                if (!$scheduledPayment->isBillable()) {
                    $output->writeln(sprintf(
                        'Skipping Scheduled Payment %s as policy is not billable',
                        $scheduledPayment->getId()
                    ));
                    $this->displayScheduledPayment($scheduledPayment, $output);
                    continue;
                }
                if (!$scheduledPayment->getPolicy()->isValidPolicy($prefix)) {
                    $output->writeln(sprintf(
                        'Skipping Scheduled Payment %s/%s as policy is not valid for prefix %s',
                        $scheduledPayment->getPolicy()->getPolicyNumber(),
                        $scheduledPayment->getId(),
                        $prefix
                    ));
                    $this->displayScheduledPayment($scheduledPayment, $output);
                    continue;
                }

                try {
                    if (!$show) {
                        $scheduledPayment = $judoPay->scheduledPayment($scheduledPayment, $prefix);
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
