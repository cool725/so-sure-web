<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;

class DaviesService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var ExcelService */
    protected $excel;

    /** @var S3Client */
    protected $s3;

    /**
     * @param LoggerInterface $logger
     * @param ExcelService    $excel
     */
    public function __construct(LoggerInterface $logger, ExcelService $excel, S3Client $s3)
    {
        $this->logger = $logger;
        $this->excel = $excel;
        $this->s3 = $se;
    }

    public function import()
    {
        // check s3
        // foreach file
        // parse mail
        // save excel file
        // export to csv
        // parse csv into objects
        // insert/update objects
        // cleanup
        // archive s3 file
        
    }
}
