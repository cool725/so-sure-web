<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\File\S3FileRepository")
 */
class SalvaPaymentFile extends DailyTransactionUploadFile
{
    public function getS3FileName()
    {
        return null;
    }
}
