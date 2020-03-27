<?php
namespace AppBundle\Service;

use phpseclib\Net\SFTP;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\DaviesHandlerClaim;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\DaviesFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;

abstract class ExcelSftpService
{
    use CurrencyTrait;
    use DateTrait;

    const PROCESSED_FOLDER = 'Processed';
    const FAILED_FOLDER = 'Failed';

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
    protected $server;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $zipPassword;

    /** @var string */
    protected $environment;

    protected $warnings = [];
    protected $errors = [];
    protected $sosureActions = [];

    /** @var SFTP */
    protected $sftp;

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

    public function setUsername($username)
    {
        $this->username = $username;
    }

    public function setPassword($password)
    {
        $this->password = $password;
    }

    public function setZipPassword($zipPassword)
    {
        $this->zipPassword = $zipPassword;
    }

    public function setServer($server)
    {
        $this->server = $server;
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

    public function import($sheetName, $useMime = true, $maxParseErrors = 0, $skipCleanup = false)
    {
        $lines = [];
        $files = $this->listSftp('.xlsx');
        foreach ($files as $file) {
            $this->clearWarnings();
            $this->clearErrors();
            $tempFile = null;
            $lines[] = sprintf('Processing %s/%s', $this->path, $file);
            $processed = false;
            try {
                $tempFile = $this->downloadFile($file);
                $data = $this->parseExcel($tempFile, $sheetName, $useMime, $maxParseErrors);
                $processed = $this->processExcelData($file, $data);
            } catch (\Exception $e) {
                $processed = false;
                $this->logger->error(sprintf(
                    'Error processing %s. Moving to failed. Ex: %s',
                    $file,
                    $e->getMessage()
                ));
            }

            if ($processed) {
                $key = $this->uploadS3($tempFile, $file, self::PROCESSED_FOLDER);
                $lines[] = sprintf('Successfully imported %s and moved to processed folder', $key);
            } else {
                $key = $this->uploadS3($tempFile, $file, self::FAILED_FOLDER);
                $lines[] = sprintf('Failed to import %s and moved to failed folder', $key);
            }

            $this->postProcess();

            if (!$skipCleanup) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                $this->moveSftp($file, self::PROCESSED_FOLDER);
            } else {
                if (file_exists($tempFile)) {
                    $lines[] = sprintf('Skipping cleanup for %s', $tempFile);
                }
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

    private function loginSftp()
    {
        $this->sftp = new SFTP($this->server);
        $this->sftp->enableQuietMode();
        if (!$this->sftp->login($this->username, $this->password)) {
            throw new \Exception(sprintf(
                'Login Failed. Msg: %s',
                $this->sftp->getLastSFTPError()
            ));
        }
    }

    /**
     * @return array
     */
    public function listSftp($extension = '.zip')
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }
        $files = $this->sftp->nlist('.', false);
        if ($files === false) {
            throw new \Exception(sprintf(
                'List folder Failed. Msg: %s',
                $this->sftp->getLastSFTPError()
            ));
        }
        $list = [];
        foreach ($files as $file) {
            if (mb_stripos($file, $extension) !== false) {
                $list[] = $file;
            }
        }

        return $list;
    }

    public function moveSftp($file, $folder)
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }

        // it may take too long to process the file - if it fails, try logging in again
        if (!$this->sftp->rename($file, sprintf('%s/%s', $folder, $file))) {
            $this->loginSftp();
            if (!$this->sftp->rename($file, sprintf('%s/%s', $folder, $file))) {
                throw new \Exception(sprintf(
                    'Login Failed. Msg: %s',
                    $this->sftp->getLastSFTPError()
                ));
            }
        }
    }

    public function uploadS3($file, $name, $folder)
    {
        $now = \DateTime::createFromFormat('U', time());
        $extension = sprintf('.%s', pathinfo($name, PATHINFO_EXTENSION));
        $s3Key = sprintf(
            '%s/%s/%d/%s-%s%s',
            $this->path,
            $folder,
            $now->format('Y'),
            basename($name, $extension),
            $now->format('U'),
            $extension
        );
        $result = $this->s3->putObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $s3Key,
            'SourceFile' => $file,
        ));

        $file = $this->getNewS3File();
        $file->setBucket($this->bucket);
        $file->setKey($s3Key);
        $file->setSuccess($folder == self::PROCESSED_FOLDER);
        $this->dm->persist($file);
        $this->dm->flush();

        return $s3Key;
    }

    public function generateTempFile()
    {
        $tempFile = tempnam(sys_get_temp_dir(), "s3email");

        return $tempFile;
    }

    public function downloadFile($file)
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }
        $tempFile = $this->generateTempFile();

        if ($this->sftp->get($file, $tempFile) === false) {
            throw new \Exception(sprintf(
                'Failed to download file %s. Msg: %s',
                $file,
                $this->sftp->getLastSFTPError()
            ));
        }

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
                $msg = sprintf("Unable to import claim. Error: %s, Line: %s", $e->getMessage(), json_encode($line));
                $this->logger->info($msg);
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
