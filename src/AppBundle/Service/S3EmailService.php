<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\DaviesClaim;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\DaviesFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;

abstract class S3EmailService
{
    use CurrencyTrait;
    use DateTrait;

    const PROCESSED_FOLDER = 'processed';
    const UNPROCESSED_FOLDER = 'unprocessed';
    const FAILED_FOLDER = 'failed';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var ExcelService */
    protected $excel;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $bucket;

    /** @var string */
    protected $path;

    /** @var string */
    protected $environment;

    protected $warnings = [];
    protected $errors = [];
    protected $sosureActions = [];

    public function setDm($dm)
    {
        $this->dm = $dm;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setExcel($excel)
    {
        $this->excel = $excel;
    }

    public function setS3($s3)
    {
        $this->s3 = $s3;
    }

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
    }

    public function setPath($pathPrefix)
    {
        $this->path = sprintf('%s/%s', $pathPrefix, $this->environment);
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    public function clearWarnings()
    {
        $this->warnings = [];
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function clearErrors()
    {
        $this->errors = [];
    }

    public function getSoSureActions()
    {
        return $this->sosureActions;
    }

    public function clearSoSureActions()
    {
        $this->sosureActions = [];
    }

    abstract public function processExcelData($key, $data);
    abstract public function postProcess();
    abstract public function getNewS3File();
    abstract public function getColumnsFromSheetName($sheetName);
    abstract public function createLineObject($line, $columns);

    public function import($sheetName, $useMime = true, $maxParseErrors = 0)
    {
        $lines = [];
        $keys = $this->listS3();
        foreach ($keys as $key) {
            $this->clearWarnings();
            $this->clearErrors();
            $excelFile = null;
            $emailFile = null;
            $lines[] = sprintf('Processing %s/%s', $this->path, $key);
            $processed = false;
            try {
                $emailFile = $this->downloadEmail($key);
                if ($excelFile = $this->extractExcelFromEmail($emailFile)) {
                    $data = $this->parseExcel($excelFile, $sheetName, $useMime, $maxParseErrors);
                    $processed = $this->processExcelData($key, $data);
                } else {
                    throw new \Exception('Unable to locate excel file in email message');
                }
            } catch (\Exception $e) {
                $processed = false;
                $this->logger->error(sprintf('Error processing %s. Moving to failed. Ex: %s', $key, $e->getMessage()));
            }

            if ($processed) {
                $this->moveS3($key, self::PROCESSED_FOLDER);
                $lines[] = sprintf('Successfully imported %s/%s and moved to processed folder', $this->path, $key);
            } else {
                $this->moveS3($key, self::FAILED_FOLDER);
                $lines[] = sprintf('Failed to import %s/%s and moved to failed folder', $this->path, $key);
            }

            $this->postProcess();

            if (file_exists($excelFile)) {
                unlink($excelFile);
            }
            if (file_exists($emailFile)) {
                unlink($emailFile);
            }
        }

        return $lines;
    }

    public function importFile($file, $sheetName, $useMime = true, $maxParseErrors = 0)
    {
        $lines = [];
        $lines[] = sprintf('Processing %s', $file);
        $processed = false;
        try {
            $data = $this->parseExcel($file, $sheetName, $useMime, $maxParseErrors);
            $processed = $this->processExcelData($file, $data);
        } catch (\Exception $e) {
            $processed = false;
            $this->logger->error(sprintf('Error processing %s. Ex: %s', $file, $e->getMessage()));
        }

        if ($processed) {
            $lines[] = sprintf('Successfully imported %s', $file);
        } else {
            $lines[] = sprintf('Failed to import %s', $file);
        }
        $this->postProcess();

        return $lines;
    }

    /**
     * @return array
     */
    public function listS3()
    {
        $iterator = $this->s3->getIterator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => sprintf('%s/unprocessed/', $this->path),
        ]);

        $keys = [];
        foreach ($iterator as $object) {
            if ($object['Size'] > 0 &&
                mb_stripos($object['Key'], 'AMAZON_SES_SETUP_NOTIFICATION') === false) {
                $keys[] = $object['Key'];
            }
        }

        return $keys;
    }

    public function moveS3($sourceKey, $folder)
    {
        $now = new \DateTime();
        $destKey = str_replace(
            sprintf('/%s/', self::UNPROCESSED_FOLDER),
            sprintf('/%s/%d/', $folder, $now->format('Y')),
            $sourceKey
        );
        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => sprintf("%s/%s", $this->bucket, $sourceKey),
            'Key' => $destKey,
        ]);
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $sourceKey,
        ]);

        $file = $this->getNewS3File();
        $file->setBucket($this->bucket);
        $file->setKey($destKey);
        $file->setSuccess($folder == self::PROCESSED_FOLDER);
        $this->dm->persist($file);
        $this->dm->flush();
    }

    public function generateTempFile()
    {
        $tempFile = tempnam(sys_get_temp_dir(), "s3email");

        return $tempFile;
    }

    public function downloadEmail($s3Key)
    {
        $tempFile = $this->generateTempFile();

        $result = $this->s3->getObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $s3Key,
            'SaveAs' => $tempFile,
        ));

        return $tempFile;
    }

    /**
     * @param string $filename
     *
     * @return string Excel tmp file
     */
    public function extractExcelFromEmail($filename)
    {
        $excelFile = null;
        $fileTxt = implode("", file($filename));
        $mime = mailparse_msg_parse_file($filename);
        try {
            $structure = mailparse_msg_get_structure($mime);
            foreach ($structure as $element) {
                $mimePart = mailparse_msg_get_part($mime, $element);
                $bodyParts = mailparse_msg_get_part_data($mimePart);
                if (isset($bodyParts['content-type'])) {
                    try {
                        $fileExtension = $this->excel->getFileExtension($bodyParts['content-type']);
                        $testFile = sprintf("%s.%s", $this->generateTempFile(), $fileExtension);
                        if ($file = fopen($testFile, "wb")) {
                            fputs($file, mailparse_msg_extract_part_file($mimePart, $filename, null));
                            fclose($file);
                            $excelFile = $testFile;
                        }
                    } catch (\Exception $e) {
                        $this->logger->debug(
                            sprintf('Skipping email attachment %s', $bodyParts['content-type']),
                            ['exception' => $e]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $excelFile = null;
            $this->logger->error(sprintf("Unable to parse email. Ex: %s", $e->getMessage()));
        }
        mailparse_msg_free($mime);

        return $excelFile;
    }

    public function parseExcel($filename, $sheetName, $useMime = true, $maxParseErrors = 0)
    {
        $tempFile = $this->generateTempFile();
        $this->excel->convertToCsv($filename, $tempFile, $sheetName, $useMime);
        $lines = array_map('str_getcsv', file($tempFile));
        unlink($tempFile);

        $data = [];
        $row = -1;
        $columns = -1;
        $parseErrors = 0;
        foreach ($lines as $line) {
            $row++;
            try {
                $columns = $this->getColumnsFromSheetName($sheetName);
                // There may be additional blank columns that need to be ignored
                $line = array_slice($line, 0, $columns);

                // If the claim doesn't have correct data, just ignore
                if ($lineObject = $this->createLineObject($line, $columns)) {
                    $data[] = $lineObject;
                }
            } catch (\Exception $e) {
                $msg = sprintf("Error parsing line. Ex: %s, Line: %s", $e->getMessage(), json_encode($line));
                $this->logger->error($msg);
                $this->errors['Unknown'][] = $msg;
                $parseErrors++;

                if ($parseErrors > $maxParseErrors) {
                    throw $e;
                }
            }
        }

        if (count($data) == 0) {
            throw new \Exception(sprintf('Unable to find any data to process in file'));
        }

        return $data;
    }

    protected function postcodeCompare($postcodeA, $postcodeB)
    {
        $postcodeA = new Postcode($postcodeA);
        $postcodeB = new Postcode($postcodeB);
        /*
        if (!$postcodeA|| !$postcodeB) {
            return false;
        }*/

        return $postcodeA->normalise() === $postcodeB->normalise();
    }
}
