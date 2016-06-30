<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\CurrencyTrait;

class BarclaysService
{
    use CurrencyTrait;

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
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function processCsv($barclaysFile)
    {
        $filename = $barclaysFile->getFile();

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
                if ($firstRow) {
                    $firstRow = false;
                    continue;
                }
                if (!$header) {
                    // header has a trailing , :P
                    unset($row[count($row) - 1]);
                    $header = $row;
                } else {
                    $line = array_combine($header, $row);
                    $lines[] = $line;
                    if ($line['Transaction currency'] != "GBP") {
                        throw new \Exception('Unknown currency');
                    }

                    if ($line['Transaction type'] == "Sale") {
                        $amount = $line['Transaction amount'];
                        $payments += $line['Transaction amount'];
                        $numPayments++;
                    } elseif ($line['Transaction type'] == "Sales Refund") {
                        $amount = 0 - $line['Transaction amount'];
                        $refunds += $line['Transaction amount'];
                        $numRefunds++;
                    }
                    $total += $amount;

                    $transactionDate = new \DateTime($line['Transaction date']);
                    if (!isset($dailyTransaction[$transactionDate->format('Ymd')])) {
                        $dailyTransaction[$transactionDate->format('Ymd')] = 0;
                    }
                    $dailyTransaction[$transactionDate->format('Ymd')] += $amount;

                    $processedDate = new \DateTime($line['Processed date']);
                    if (!isset($dailyProcessing[$processedDate->format('Ymd')])) {
                        $dailyProcessing[$processedDate->format('Ymd')] = 0;
                    }
                    $dailyProcessing[$processedDate->format('Ymd')] += $amount;

                    if (!$maxDate || $maxDate > $processedDate) {
                        $maxDate = $processedDate;
                    }
                }
            }
            fclose($handle);
        }
        
        // TODO: Attempt to find JudoPayments that match the transaction date, card number, amount, card type, and sale/refund criteria
        // and add transaction details if found
        // if none or more than one, flag record
        // also record number of transactions found in metadata

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

        $barclaysFile->addMetadata('total', $data['total']);
        $barclaysFile->addMetadata('payments', $data['payments']);
        $barclaysFile->addMetadata('numPayments', $data['numPayments']);
        $barclaysFile->addMetadata('refunds', $data['refunds']);
        $barclaysFile->addMetadata('numRefunds', $data['numRefunds']);
        $barclaysFile->setDate($data['date']);
        $barclaysFile->setDailyTransaction($data['dailyTransaction']);
        $barclaysFile->setDailyProcessing($data['dailyProcessing']);

        return $data;
    }
}
