<?php
namespace AppBundle\Service;

use AppBundle\Repository\ChargebackPaymentRepository;
use AppBundle\Repository\JudoPaymentRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\Payment\Payment;

class CashflowsService
{
    use DateTrait;
    use CurrencyTrait;

    const MID = '8008566';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    )
    {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function processCsv($cashflowsFile)
    {
        $filename = $cashflowsFile->getFile();

        $firstRow = true;
        $header = null;
        $lines = array();
        $dailyTransaction = array();
        $dailyProcessing = array();

        $payments = 0;
        $numPayments = 0;
        $refunds = 0;
        $numRefunds = 0;
        $total = 0;
        $maxDate = null;

        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    foreach ($row as $item) {
                        $header[] = str_replace("'", "", $item);
                    }
                } else {
                    $line = array_combine($header, $row);
                    $lines[] = $line;

                    $amount = 0;
                    if ($line['Type'] == 'Sale Settlement') {
                        $amount = $line['Credit'];
                        $payments += $line['Credit'];
                        $numPayments++;
                    } elseif ($line['Type'] == 'Refund') {
                        $amount = 0 - $line['Debit'];
                        $refunds += $line['Debit'];
                        $numRefunds++;
                    }
                    $total += $amount;

                    $dateTimeString = sprintf('%s %s', $line['Date'], $line['Time']);
                    $transactionDate = \DateTime::createFromFormat('d/m/Y H:i:s', $dateTimeString);
                    if (!isset($dailyTransaction[$transactionDate->format('Ymd')])) {
                        $dailyTransaction[$transactionDate->format('Ymd')] = 0;
                    }
                    $dailyTransaction[$transactionDate->format('Ymd')] += $amount;

                    $processedDate = \DateTime::createFromFormat('d/m/Y', $line['Matured']);
                    if ($processedDate) {
                        if (!isset($dailyProcessing[$processedDate->format('Ymd')])) {
                            $dailyProcessing[$processedDate->format('Ymd')] = 0;
                        }
                        $dailyProcessing[$processedDate->format('Ymd')] += $amount;

                        if (!$maxDate || $maxDate > $processedDate) {
                            $maxDate = $processedDate;
                        }
                    }
                }
            }
            fclose($handle);
        }

        $data = [
            'total' => $this->toTwoDp($total),
            'payments' => $this->toTwoDp($payments),
            'numPayments' => $numPayments,
            'refunds' => $this->toTwoDp($refunds),
            'numRefunds' => $numRefunds,
            'date' => $maxDate,
            'data' => $lines,
            'dailyTransaction' => $dailyTransaction,
            'dailyProcessing' => $dailyProcessing,
        ];

        $cashflowsFile->addMetadata('total', $data['total']);
        $cashflowsFile->addMetadata('payments', $data['payments']);
        $cashflowsFile->addMetadata('numPayments', $data['numPayments']);
        $cashflowsFile->addMetadata('refunds', $data['refunds']);
        $cashflowsFile->addMetadata('numRefunds', $data['numRefunds']);
        $cashflowsFile->setDate($data['date']);
        $cashflowsFile->setDailyTransaction($data['dailyTransaction']);
        $cashflowsFile->setDailyProcessing($data['dailyProcessing']);

        return $data;
    }
}
