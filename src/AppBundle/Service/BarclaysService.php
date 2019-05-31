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

class BarclaysService
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

                    $amount = 0;
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

        // Attempt to match barclay card transaction against JudoPayment record
        $matchedTransactions = 0;
        /** @var JudoPaymentRepository $paymentRepo */
        $paymentRepo = $this->dm->getRepository(JudoPayment::class);
        foreach ($lines as $line) {
            $transactionDate = new \DateTime($line['Transaction date']);
            $amount = 0;
            if ($line['Transaction type'] == "Sale") {
                $amount = $line['Transaction amount'];
            } elseif ($line['Transaction type'] == "Sales Refund") {
                $amount = 0 - $line['Transaction amount'];
            }
            $cardNumbers = explode('*', $line['Card number']);
            $cardLastFour = $cardNumbers[count($cardNumbers) - 1];
            $ref = str_replace("'", "", $line['Acquirer reference number']);
            $transactions = $paymentRepo->findTransaction($transactionDate, $amount, $cardLastFour);
            if (count($transactions) == 1) {
                foreach ($transactions as $transaction) {
                    $transaction->setBarclaysReference($ref);
                    $matchedTransactions++;
                }
            } else {
                // we many have manually added the transaction
                /** @var Payment $existingRef */
                $existingRef = $paymentRepo->findOneBy(['barclaysReference' => $ref]);
                if (!$existingRef) {
                    $this->logger->debug(sprintf(
                        'Unable to find matching transaction for %s',
                        $line['Acquirer reference number']
                    ));
                }
            }
        }
        $this->dm->flush();

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
            'matchedTransactions' => $matchedTransactions,
        ];

        $barclaysFile->addMetadata('total', $data['total']);
        $barclaysFile->addMetadata('payments', $data['payments']);
        $barclaysFile->addMetadata('numPayments', $data['numPayments']);
        $barclaysFile->addMetadata('refunds', $data['refunds']);
        $barclaysFile->addMetadata('numRefunds', $data['numRefunds']);
        $barclaysFile->addMetadata('matchedTransactions', $data['matchedTransactions']);
        $barclaysFile->setDate($data['date']);
        $barclaysFile->setDailyTransaction($data['dailyTransaction']);
        $barclaysFile->setDailyProcessing($data['dailyProcessing']);

        return $data;
    }

    public function processStatementCsv($barclaysStatementFile)
    {
        /** @var ChargebackPaymentRepository $repo */
        $repo = $this->dm->getRepository(ChargebackPayment::class);

        $filename = $barclaysStatementFile->getFile();

        $header = null;
        $lines = array();
        $chargebacks = array();

        $invoiceTotal = 0;
        $transactionTotal = 0;
        $date = null;

        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    $updatedRow = [];
                    foreach ($row as $item) {
                        // there are a few (£) items that don't import properly, so strip out
                        $updatedRow[] = trim(preg_replace('/\([^\)]*\)/', '', $item));
                    }
                    $header = $updatedRow;
                } elseif (count($header) != count($row)) {
                    continue;
                } else {
                    $line = array_combine($header, $row);
                    $lines[] = $line;
                    if ($line['Record'] == 'Merchant Invoice Total') {
                        $date = \DateTime::createFromFormat('jS M Y', trim($line['Account Period From']));
                        $date = $this->startOfDay($date);
                        $invoiceTotal = $line['TOTAL'];
                    } elseif ($line['Record'] == 'Transaction Charges Total') {
                        // throw new \Exception(print_r($line, true));
                        $transactionTotal = $line['TOTAL'];
                    } elseif ($line['Record'] == 'Statement of Account Details' &&
                        mb_stripos($line['charge Description'], 'Chargeback') !== false) {
                        $ref = trim(str_replace('Chargeback - Ref ', '', $line['charge Description']));
                        $amount = $this->toTwoDp(0 - $line['TOTAL']);
                        $id = null;
                        $data = ['reference' => $ref, 'amount' => $amount, 'date' => $date];
                        /** @var ChargebackPayment $chargeback */
                        $chargeback = $repo->findOneBy($data);
                        if (!$chargeback) {
                            $chargeback = new ChargebackPayment();
                            $chargeback->setSource(Payment::SOURCE_ADMIN);
                            $chargeback->setReference($ref);
                            $chargeback->setAmount($amount);
                            $chargeback->setDate($date);
                            // set commission as a positive value and then invert it.
                            $chargeback->setCommission();
                            $chargeback->setRefundTotalCommission($chargeback->getTotalCommission());
                            $this->dm->persist($chargeback);
                            $this->dm->flush();
                            $this->logger->warning(sprintf(
                                'Barclays Statement Upload for %s has an unprocessed chargeback ref %s for %0.2f',
                                $date->format(\DateTime::ATOM),
                                $ref,
                                $amount
                            ));
                        }
                        $data['id'] = $chargeback->getId();
                        $chargebacks[$ref] = $data;
                    }
                }
            }
            fclose($handle);
        }

        $barclaysStatementFile->addMetadata('invoiceTotal', $invoiceTotal);
        $barclaysStatementFile->addMetadata('transactionTotal', $transactionTotal);
        $barclaysStatementFile->setDate($date);
        $barclaysStatementFile->setChargebacks($chargebacks);
    }

    public function processStatementNewCsv($barclaysStatementFile)
    {
        /** @var ChargebackPaymentRepository $repo */
        $repo = $this->dm->getRepository(ChargebackPayment::class);

        $filename = $barclaysStatementFile->getFile();

        $header = null;
        $lines = array();
        $chargebacks = array();

        $invoiceTotal = 0;
        $transactionTotal = 0;
        $merchantId = null;
        $date = null;

        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 5000)) !== false) {
                if (!$header) {
                    $header = [];
                    $count = 0;
                    foreach ($row as $item) {
                        if (!in_array(trim($item), $header)) {
                            $header[] = trim($item);
                        } else {
                            $header[] = sprintf('%s_%d', trim($item), $count);
                        }
                        $count++;
                    }
                } else {
                    $line = array_combine($header, $row);
                    $lines[] = $line;
                    if ($line['MERCHANT ID'] != '') {
                        $merchantId = $line['MERCHANT ID'];
                    }
                    if (in_array($line['CHARGE BREAKDOWN'], ['Transaction charges', 'Activity based charges'])) {
                        $invoiceTotal += $line['CHARGE BREAKDOWN TOTAL'];
                    }
                    if ($line['AMOUNT IN SETTLEMENT CURRENCY'] != '') {
                        // throw new \Exception(print_r($line, true));
                        $transactionTotal += $line['AMOUNT IN SETTLEMENT CURRENCY'];
                    }
                    if ($line['DATE PROCESSED'] != '') {
                        $processedDate = \DateTime::createFromFormat('dmY', trim($line['DATE PROCESSED']));
                        $processedDate = $this->startOfDay($processedDate);
                        if (!$date || $processedDate < $date) {
                            $date = $processedDate;
                        }
                    }
                    if (trim($line['CHARGE GROUP']) == 'Chargebacks') {
                        //throw new \Exception(print_r($line, true));
                        $ref = trim(str_replace($merchantId, '', $line['CHARGE TYPE']));
                        $ref = str_replace('/', '', $ref);
                        $amount = $this->toTwoDp(0 - $line['CHARGE TOTAL']);
                        $id = null;
                        /** @var ChargebackPayment $chargeback */
                        $chargeback = $repo->findOneBy(['reference' => $ref]);
                        if (!$chargeback) {
                            $chargeback = new ChargebackPayment();
                            $chargeback->setSource(Payment::SOURCE_ADMIN);
                            $chargeback->setReference($ref);
                            $chargeback->setAmount($amount);
                            $chargeback->setDate($date);
                            $this->dm->persist($chargeback);
                            $this->dm->flush();
                            // @codingStandardsIgnoreStart
                            $this->logger->warning(sprintf(
                                'Barclays Statement Upload for %s has an unprocessed chargeback ref %s for %0.2f. Make sure to manually add commission to the chargeback payment.',
                                $date->format(\DateTime::ATOM),
                                $ref,
                                $amount
                            ));
                            // @codingStandardsIgnoreEnd
                        }
                        $data['id'] = $chargeback->getId();
                        $chargebacks[$ref] = $data;
                    }
                }
            }
            fclose($handle);
        }
        if (!$date) {
            throw new \Exception('Unable to parse file');
        }
        $barclaysStatementFile->addMetadata('invoiceTotal', $invoiceTotal);
        $barclaysStatementFile->addMetadata('transactionTotal', $transactionTotal);
        $barclaysStatementFile->setDate($date);
        $barclaysStatementFile->setChargebacks($chargebacks);
    }
}
