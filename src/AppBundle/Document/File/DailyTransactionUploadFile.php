<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Gedmo\Mapping\Annotation as Gedmo;

abstract class DailyTransactionUploadFile extends UploadFile
{
    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyTransaction = array();

    public function getDailyTransaction()
    {
        return $this->dailyTransaction;
    }

    public function setDailyTransaction($dailyTransaction)
    {
        $this->dailyTransaction = $dailyTransaction;
    }

    public static function combineDailyTransactions($files)
    {
        return self::combineFiles($files, 'getDailyTransaction');
    }
}
