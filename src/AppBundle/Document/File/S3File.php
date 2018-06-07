<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\File\S3FileRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("fileType")
 * @MongoDB\DiscriminatorMap({
 *      "salvaPayment"="SalvaPaymentFile",
 *      "salvaPolicy"="SalvaPolicyFile",
 *      "judo"="JudoFile",
 *      "lloyds"="LloydsFile",
 *      "reconciliation"="ReconciliationFile",
 *      "barclays"="BarclaysFile",
 *      "barclaysStatement"="BarclaysStatementFile",
 *      "policySchedule"="PolicyScheduleFile",
 *      "policyTerms"="PolicyTermsFile",
 *      "invoice"="InvoiceFile",
 *      "davies"="DaviesFile",
 *      "screenUpload"="ScreenUploadFile",
 *      "imeiUpload"="ImeiUploadFile",
 *      "brightstar"="BrightstarFile",
 *      "picsure"="PicSureFile",
 *      "accesspay"="AccessPayFile",
 *      "bacsReportAddacs"="BacsReportAddacsFile",
 *      "bacsReportAuddis"="BacsReportAuddisFile",
 *      "bacsReportArudd"="BacsReportAruddFile",
 *      "bacsReportDdic"="BacsReportDdicFile",
 *      "bacsReportInput"="BacsReportInputFile",
 *      "ddNotification"="DirectDebitNotificationFile"
 * })
 * @Gedmo\Loggable
 */
class S3File
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $bucket;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $key;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $metadata = array();

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function getBucket()
    {
        return $this->bucket;
    }

    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function setKey($key)
    {
        $this->key = $key;
    }

    public function getFilename()
    {
        $items = explode('/', $this->getKey());

        return $items[count($items) - 1];
    }

    public function getMetadata()
    {
        return $this->metadata;
    }

    public function addMetadata($key, $value)
    {
        $this->metadata[$key] = $value;
    }

    public function setMetadata($metadata)
    {
        $this->metadata = $metadata;
    }

    public function getFileType()
    {
        $names = explode('\\', get_class($this));

        return $names[count($names) - 1];
    }
}
