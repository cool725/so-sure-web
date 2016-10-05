<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\CurrencyTrait;

class LloydsService
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

    public function processCsv($lloydsFile)
    {
        $filename = $lloydsFile->getFile();

        $header = null;
        $lines = array();
        $dailyReceived = array();
        $dailyProcessing = array();

        $total = 0;
        $maxDate = null;

        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    // header has a trailing , :P
                    unset($row[count($row) - 1]);
                    $header = $row;
                } else {
                    $line = array_combine($header, $row);

                    $processedDates = explode('8008566', $line['Transaction Description']);
                    // Expected something like MDIR  8008566SEP21 8008566
                    if (stripos($line['Transaction Description'], 'MDIR') === false ||
                        count($processedDates) < 2) {
                        $this->logger->warning(sprintf('Skipping line as unable to parse description. %s', implode($line)));
                        continue;
                    }
                    $processedDate = new \DateTime($processedDates[1]);

                    if (is_numeric($line['Credit Amount'])) {
                        $amount = $line['Credit Amount'];
                    } elseif (is_numeric($line['Debit Amount'])) {
                        $amount = 0 - $line['Debit Amount'];
                    }
                    $total += $amount;

                    $receivedDate = \DateTime::createFromFormat("d/m/Y", $line['Transaction Date']);
                    if (!isset($dailyReceived[$receivedDate->format('Ymd')])) {
                        $dailyReceived[$receivedDate->format('Ymd')] = 0;
                    }
                    $dailyReceived[$receivedDate->format('Ymd')] += $amount;

                    if (!isset($dailyProcessing[$processedDate->format('Ymd')])) {
                        $dailyProcessing[$processedDate->format('Ymd')] = 0;
                    }
                    $dailyProcessing[$processedDate->format('Ymd')] += $amount;

                    if (!$maxDate || $maxDate > $receivedDate) {
                        $maxDate = $receivedDate;
                    }
                    $lines[] = $line;
                }
            }
            fclose($handle);
        }

        $data = [
            'total' => $this->toTwoDp($total),
            'date' => $maxDate,
            'data' => $lines,
            'dailyReceived' => $dailyReceived,
            'dailyProcessing' => $dailyProcessing,
        ];

        $lloydsFile->addMetadata('total', $data['total']);
        $lloydsFile->setDate($data['date']);
        $lloydsFile->setDailyReceived($data['dailyReceived']);
        $lloydsFile->setDailyProcessing($data['dailyProcessing']);

        return $data;
    }
}
