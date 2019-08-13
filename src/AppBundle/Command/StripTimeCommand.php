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

/**
 * Implements a command to make all future scheduled payments be scheduled for 3AM.
 */
class StripTimeCommand extends ContainerAwareCommand
{
    /** @var DocumentManager */
    private $dm;

    /**
     * Injects required dependencies to command.
     * @param DocumentManager $dm is the document manager used.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * @Override
     */
    protected function configure()
    {
        $this->setName("sosure:schedule:strip")
            ->setDescription("Schedule all future scheduled payments for 3AM.")
            ->addOption(
                "wet",
                "w",
                InputOption::VALUE_NONE,
                "If not present, potential modifications are reported but not actioned"
            );
    }

    /**
     * @Override
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $wet = $input->getOption("wet");
        $output->writeln($wet ? "Wet Run, changes will be saved" : "Dry Run, no changes will be saved");
        /** @var ScheduledPaymentRepository $scheduledPaymentRepository */
        $scheduledPaymentRepository = $this->dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $scheduledPaymentRepository->findAllScheduled();
        foreach ($scheduledPayments as $scheduledPayment) {
            $date = clone $scheduledPayment->getScheduled();
            $date->setTime(3, 0);
            $output->writeln(sprintf(
                "%s: %s -> %s",
                $scheduledPayment->getId(),
                $scheduledPayment->getScheduled()->format("d-m-Y H:i"),
                $date->format("d-m-Y H:i")
            ));
            if ($wet) {
                $scheduledPayment->setNotes(sprintf(
                    "%s. Rescheduled from %s to %s",
                    $scheduledPayment->getNotes(),
                    $scheduledPayment->getScheduled()->format("d-m-Y H:i"),
                    $date->format("d-m-Y H:i")
                ));
                $scheduledPayment->setScheduled($date);
                $this->dm->persist($scheduledPayment);
            }
        }
        if ($wet) {
            $this->dm->flush();
        }
    }
}
