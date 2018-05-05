<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Validator\Constraints as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\Document
 * @Vich\Uploadable
 */
class AccessPayFile extends UploadFile
{
    use CurrencyTrait;

    const STATUS_PENDING = 'pending';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     *
     * @Vich\UploadableField(mapping="adminS3Mapping", fileNameProperty="fileName")
     *
     * @var File
     */
    protected $file;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="10")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $serialNumber;

    /**
     * @Assert\Choice({"pending", "submitted", "cancelled"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    public function __construct()
    {
        parent::__construct();
        $this->setStatus(self::STATUS_PENDING);
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function isActioned()
    {
        return in_array($this->getStatus(), [self::STATUS_SUBMITTED, self::STATUS_CANCELLED]);
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * @return string
     */
    public function getS3FileName()
    {
        $now = new \DateTime();

        return sprintf(
            'accesspay-%d-%02d-%s',
            $this->getDate()->format('Y'),
            $this->getDate()->format('m'),
            $now->format('U')
        );
    }
}
