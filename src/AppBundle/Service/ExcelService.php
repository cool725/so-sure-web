<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class ExcelService
{
    const FILETYPE_XLS = 'Excel5';
    const FILETYPE_XLSX = 'Excel2007';

    const CACHE_SIZE = '10MB';
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getExtensionFileType($inFile)
    {
        $extension = pathinfo($inFile, PATHINFO_EXTENSION);
        if ($extension == 'xls') {
            return self::FILETYPE_XLS;
        } elseif ($extension == 'xlsx') {
            return self::FILETYPE_XLSX;
        }

        return null;
    }

    public function getMimeFileType($inFile)
    {
        $mimeType = mime_content_type($inFile);

        return $this->getFileFormat($mimeType);
    }

    public function getFileExtension($mimeType)
    {
        $format = $this->getFileFormat($mimeType);
        if ($format == self::FILETYPE_XLS) {
            return "xls";
        } elseif ($format == self::FILETYPE_XLSX) {
            return "xlsx";
        }

        return null;
    }

    public function getFileFormat($mimeType)
    {
        if (in_array($mimeType, [
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ])) {
            return self::FILETYPE_XLSX;
        } elseif (in_array($mimeType, [
            "application/vnd.ms-excel",
            "application/vnd.ms-office",
            "application/CDFV2-unknown",
            "application/octet-stream",
            "application/x-msexcel",
        ])) {
            return self::FILETYPE_XLS;
        } else {
            throw new \Exception(sprintf('Unknown excel mime type %s', $mimeType));
        }
    }

    public function convertToCsv($inFile, $outFile, $sheetName = null, $useMime = true)
    {
        try {
            $cacheMethod = \PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
            $cacheSettings = array('memoryCacheSize' => self::CACHE_SIZE);
            \PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);

            if ($useMime) {
                $fileType = $this->getMimeFileType($inFile);
            } else {
                $fileType = $this->getExtensionFileType($inFile);
            }
            if (!$fileType) {
                throw new \Exception('Unknown file type');
            }
            /** @var \PHPExcel_Reader_Abstract $reader */
            $reader = \PHPExcel_IOFactory::createReader($fileType);
            $reader->setReadDataOnly(true);

            if ($sheetName) {
                $reader->setLoadSheetsOnly($sheetName);
            }

            $excel = $reader->load($inFile);

            if (!$sheetName) {
                $objWorksheet = $excel->getActiveSheet();
            } else {
                $objWorksheet = $excel->getSheetByName($sheetName);
            }

            if (!$objWorksheet) {
                throw new \Exception(sprintf(
                    'Unable to open excel file %s using sheet name %s. Perhaps the sheet name is incorrect?',
                    $inFile,
                    $sheetName
                ));
            }

            foreach ($objWorksheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $cell->setValue(str_replace(PHP_EOL, ' ', $cell->getValue()));
                }
            }

            $writer = \PHPExcel_IOFactory::createWriter($excel, 'CSV');
            $writer->save($outFile);

            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error converting %s to %s Ex: %s',
                $inFile,
                $outFile,
                $e->getMessage()
            ));

            return false;
        }
    }
}
