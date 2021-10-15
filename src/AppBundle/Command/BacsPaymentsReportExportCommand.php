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
use AppBundle\Service\MailerService;

/**
 * Commandline interface for payment related functionality.
 */
class BacsPaymentsReportExportCommand extends ContainerAwareCommand
{
    const COMMAND_REPORT_NAME = 'Bacs Payment Report';
    const DEFAULT_EMAIL_ADDRESS = 'tech+ops@so-sure.com';
    const DEFAULT_REPORT_PERIOD_DAYS = '-31';
    const FILE_NAME = 'bacs-payments';
    const BUCKET_FOLDER = 'reports';

    /** @var DocumentManager $dm */
    protected $dm;

    /** @var MailerService */
    protected $mailerService;

    /**
     * Builds the command object.
     * @param DocumentManager $dm is used to access payment records.
     */
    public function __construct(
        DocumentManager $dm,
        MailerService $mailerService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->mailerService = $mailerService;
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
                InputOption::VALUE_OPTIONAL,
                'The start date for the range of bacs payments to fetch e.g. 2021-01-01'
            )
            ->addOption(
                'end',
                null,
                InputOption::VALUE_OPTIONAL,
                'The start date for the range of bacs payments to fetch e.g. 2021-01-31'
            )
            ->addOption(
                'email-accounts',
                null,
                InputOption::VALUE_REQUIRED,
                'What email address(es) to send to',
                self::DEFAULT_EMAIL_ADDRESS
            )
            ->addOption(
                'output-to-file',
                null,
                InputOption::VALUE_OPTIONAL,
                'Optional output folder and file name'
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
        $outputToFile = $input->getOption('output-to-file');
        $emailAccounts = $input->getOption('email-accounts');

        if (!$start) {
            // default start to 31 days in the past
            $start = new \DateTime();
            $start = $start->modify(self::DEFAULT_REPORT_PERIOD_DAYS . ' day')->format('Y-m-d');
            $output->writeln("start: ".$start);
        }
        if (!$end) {
            // default end of report up until today
            $end = new \DateTime();
            $end = $end->format('Y-m-d');
            $output->writeln("end: ".$end);
        }

        if ($outputToFile) {
            $file = $outputToFile;
        } else {
            $fileName = self::FILE_NAME.'-'.time().".csv";
            $file = "/tmp/" . $fileName;
        }

        $payments = $bacsPaymentRepo->getBacsDateRange(new \DateTime($start), new \DateTime($end));

        $csv = fopen($file, "w");
        $headers = [
            "Payment ID",
            "Policy Number",
            "Policy ID",
            "Payment Date",
            "Policy Start Date",
            "Name",
            "Email",
            "Amount",
            "Status"
        ];
        fputcsv($csv, $headers);

        $statusCount = [];
        $count=0;
        $output->writeln("Number of payments: ".count($payments));
        foreach ($payments as $payment) {
            $count++;
            if ($count % 100 == 0) {
                $output->writeln(
                    "Refining payments data... ".round(($count/count($payments))*100)."%"
                );
            }
            if ($payment->getPolicy() !== null) {
                if (!array_key_exists($payment->getStatus(), $statusCount)) {
                    $statusCount[$payment->getStatus()] = 0;
                }
                $statusCount[$payment->getStatus()]++;
                $row = [
                    $payment->getId(),
                    $payment->getPolicy()->getPolicyNumber(),
                    $payment->getPolicy()->getId(),
                    $payment->getDate()->format('Y-m-d H:i'),
                    $payment->getPolicy()->getStart()->format('Y-m-d H:i'),
                    $payment->getPolicy()->getUser()->getFirstName()." ".
                    $payment->getPolicy()->getUser()->getLastName(),
                    $payment->getPolicy()->getUser()->getEmail(),
                    $payment->getAmount(),
                    $payment->getStatus()
                ];
                fputcsv($csv, $row);
            }
        }
        fclose($csv);

        $body = "Start Date: ".$start."<br />";
        $body .= "End Date: ".$end."<br />";
        $body .= "Statuses:<br >";
        foreach ($statusCount as $status => $count) {
            $output->writeln($status . ": " . $count);
            $body .= $status . " - " . $count . "<br />";
        }

        $this->mailerService->send(
            self::COMMAND_REPORT_NAME,
            $emailAccounts,
            $body,
            null,
            [$file]
        );

        unset($file);
    }
}
