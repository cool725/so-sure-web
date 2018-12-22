<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\CurrencyTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\File\LloydsFileRepository")
 * @Vich\Uploadable
 */
class LloydsFile extends UploadFile
{
    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     *
     * @Vich\UploadableField(mapping="adminS3Mapping", fileNameProperty="fileName")
     *
     * @var File
     */
    protected $file;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyReceived = array();

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyProcessing = array();

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyBacs = array();

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyCreditBacs = array();

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyDebitBacs = array();

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $bacsTransactions = array();

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $soSurePayment;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $aflPayment;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $salvaPayment;

    /**
     * @return string
     */
    public function getS3FileName()
    {
        $now = \DateTime::createFromFormat('U', time());

        return sprintf(
            'banking/lloyds-%d-%02d-%s',
            $this->getDate()->format('Y'),
            $this->getDate()->format('m'),
            $now->format('U')
        );
    }

    public function getDailyReceived()
    {
        return $this->dailyReceived;
    }

    public function setDailyReceived($dailyReceived)
    {
        $this->dailyReceived = $dailyReceived;
    }

    public function getDailyProcessing()
    {
        return $this->dailyProcessing;
    }

    public function setDailyProcessing($dailyProcessing)
    {
        $this->dailyProcessing = $dailyProcessing;
    }

    public function getDailyBacs()
    {
        return $this->dailyBacs;
    }

    public function setDailyBacs($dailyBacs)
    {
        $this->dailyBacs = $dailyBacs;
    }

    public function getDailyCreditBacs()
    {
        return $this->dailyCreditBacs;
    }

    public function setDailyCreditBacs($dailyCreditBacs)
    {
        $this->dailyCreditBacs = $dailyCreditBacs;
    }

    public function getDailyDebitBacs()
    {
        return $this->dailyDebitBacs;
    }

    public function setDailyDebitBacs($dailyDebitBacs)
    {
        $this->dailyDebitBacs = $dailyDebitBacs;
    }

    public function getSoSurePayment()
    {
        return $this->soSurePayment;
    }

    public function setSoSurePayment($soSurePayment)
    {
        $this->soSurePayment = $soSurePayment;
    }

    public function getAflPayment()
    {
        return $this->aflPayment;
    }

    public function setAflPayment($aflPayment)
    {
        $this->aflPayment = $aflPayment;
    }

    public function getBacsTransactions()
    {
        return $this->bacsTransactions;
    }

    public function setBacsTransactions($bacsTransactions)
    {
        $this->bacsTransactions = $bacsTransactions;
    }

    public function getBacsTransactionsByType($type, $date = null)
    {
        if (!isset($this->getBacsTransactions()[$type])) {
            return null;
        }

        $transactions = $this->getBacsTransactions()[$type];

        if (!$date) {
            return $transactions;
        }

        if (!isset($transactions[$date->format('Ymd')])) {
            return null;
        }

        return $transactions[$date->format('Ymd')];
    }

    public function getSalvaPayment()
    {
        return $this->salvaPayment;
    }

    public function setSalvaPayment($salvaPayment)
    {
        $this->salvaPayment = $salvaPayment;
    }

    public static function combineDailyReceived($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyReceived');
    }

    public static function combineDailyProcessing($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyProcessing');
    }

    public static function combineDailyBacs($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyBacs');
    }

    public static function combineDailyCreditBacs($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyCreditBacs');
    }

    public static function combineDailyDebitBacs($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyDebitBacs');
    }
}
