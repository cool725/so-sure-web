<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

class ExcelService
{
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

    public function convertToCsv($inFile, $outFile)
    {
        try {
            $cacheMethod = \PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
            $cacheSettings = array('memoryCacheSize' => self::CACHE_SIZE);
            \PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);

            $reader = \PHPExcel_IOFactory::createReader('Excel2007');
            $reader->setReadDataOnly(true);
            // $reader->setLoadSheetsOnly('Details');

            $excel = $reader->load($inFile);

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
