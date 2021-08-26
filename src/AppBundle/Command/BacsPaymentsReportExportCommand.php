<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Payment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Repository\BacsPaymentRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\Subvariant;

/**
 * Commandline interface for payment related functionality.
 */
class BacsPaymentsReportExportCommand extends ContainerAwareCommand
{
    /** @var DocumentManager $dm */
    protected $dm;

    /**
     * Builds the command object.
     * @param DocumentManager $dm is used to access payment records.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * Sets up the command.
     */
    protected function configure()
    {
        $this->setName("sosure:bacs:export")
            ->setDescription("Export bacs payments for given date range.")
            ->addOption(
                'start',
                null,
                InputOption::VALUE_REQUIRED,
                'The start date for the range of bacs payments to fetch e.g. 2021-01-01'
            )
            ->addOption(
                'end',
                null,
                InputOption::VALUE_REQUIRED,
                'The start date for the range of bacs payments to fetch e.g. 2021-01-31'
            );
    }

    /**
     * Runs the command.
     * @param InputInterface  $input  is used to receive input.
     * @param OutputInterface $output is used to send output.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var BacsPaymentRepository $bacsPaymentRepo */
        $bacsPaymentRepo = $this->dm->getRepository(BacsPayment::class);
        $start = $input->getOption('start');
        $end = $input->getOption('end');
        $payments = $bacsPaymentRepo->getBacsDateRange(new \DateTime($start), new \DateTime($end));
        echo '"Payment ID","Policy Number","Policy ID","Payment Date","Policy Start Date",'
                .'"Name","Email","Amount","Status"'."\n";
        foreach ($payments as $payment) {
            echo $payment->getId().',';
            echo $payment->getPolicy()->getPolicyNumber().',';
            echo $payment->getPolicy()->getId().',';
            echo $payment->getDate()->format('Y-m-d H:i').',';
            echo $payment->getPolicy()->getStart()->format('Y-m-d H:i').',';
            echo $payment->getPolicy()->getUser()->getFirstName()
                .' '.$payment->getPolicy()->getUser()->getLastName().',';
            echo $payment->getPolicy()->getUser()->getEmail().',';
            echo $payment->getAmount().',';
            echo $payment->getPolicy()->getBacsBankAccount()->getMandateSerialNumber().',';
            echo $payment->getStatus();
            echo "\n";
        }
    }
}
