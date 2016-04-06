<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\DaviesClaim;

class DaviesService
{
    const PROCESSED_FOLDER = 'processed';
    const UNPROCESSED_FOLDER = 'unprocessed';
    const FAILED_FOLDER = 'failed';

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

    /**
     * @param LoggerInterface $logger
     * @param ExcelService    $excel
     */
    public function __construct(LoggerInterface $logger, ExcelService $excel, S3Client $s3)
    {
        $this->logger = $logger;
        $this->excel = $excel;
        $this->s3 = $s3;
        $this->bucket = 'ops.so-sure.com';
        $this->path = 'claims-report/prod';
    }

    public function import()
    {
        $keys = $this->listS3();
        foreach ($keys as $key) {
            $processed = false;
            try {
                $emailFile = $this->downloadEmail($key);
                if ($excelFile = $this->extractExcelFromEmail($emailFile)) {
                    $claims = $this->parseExcel($excelFile);
                    $processed = $this->saveClaims($claims);
                }
            } catch (\Exception $e) {
                $processed = false;
                $this->logger->error(sprintf('Error processing %s. Moving to failed. Ex: %s', $key, $e->getMessage()));
            }

            if ($processed) {
                $this->moveS3($key, self::PROCESSED_FOLDER);
            } else {
                $this->moveS3($key, self::FAILED_FOLDER);
            }

            if (file_exists($excelFile)) {
                unlink($excelFile);
            }
            if (file_exists($emailFile)) {
                unlink($emailFile);
            }
        }
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
            if ($object['Size'] > 0) {
                $keys[] = $object['Key'];
            }
        }

        return $keys;
    }

    public function moveS3($sourceKey, $folder)
    {
        $destKey = str_replace(sprintf('/%s/', self::UNPROCESSED_FOLDER), sprintf('/%s/', $folder), $sourceKey);
        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => sprintf("%s/%s", $this->bucket, $sourceKey),
            'Key' => $destKey,
        ]);
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $sourceKey,
        ]);
    }

    public function generateTempFile()
    {
        $tempFile = tempnam(sys_get_temp_dir(), "davies");

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
                if (isset($bodyParts['content-type']) &&
                    $bodyParts['content-type'] == "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet") {
                    $excelFile = sprintf("%s.xlsx", $this->generateTempFile());
                    if ($file = fopen($excelFile, "wb")) {
                        fputs($file, mailparse_msg_extract_part_file($mimePart, $filename, null));
                        fclose($file);
                    } else {
                        $excelFile = null;
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

    public function parseExcel($filename)
    {
        $tempFile = $this->generateTempFile();
        $this->excel->convertToCsv($filename, $tempFile);
        $lines = array_map('str_getcsv', file($tempFile));
        unlink($tempFile);

        $claims = [];
        $row = -1;
        foreach ($lines as $line) {
            $row++;
            if ($row == 0) {
                continue;
            }
            try {
                $claim = DaviesClaim::create($line);
                // If the claim is a blank line, just ignore
                if ($claim) {
                    $claims[] = $claim;
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf("Error parsing line %s. Ex: %s", print_r($line), $e->getMessage()));

                throw $e;
            }
        }

        return $claims;
    }

    public function saveClaims(array $claims)
    {
        foreach ($claims as $claim) {
            // todo
            print "TODO":
            print_r($claim);
        }

        return true;
    }
}
