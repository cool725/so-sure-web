<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class BacsPayment extends Payment
{
    const STATUS_PENDING = 'pending';
    const STATUS_GENERATED = 'generated';
    const STATUS_SUBMITTED = 'submitted';
    /**
     * Money has been transfered, however, there may be an exception in next day or 2
     */
    const STATUS_TRANSFFER_WAIT_EXCEPTION = 'transfer-wait-exception';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $manual;

    /**
     * @Assert\Choice({
     *      "pending",
     *      "generated",
     *      "submitted",
     *      "transfer-wait-exception",
     *      "success",
     *      "failure"
     * }, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="10")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $serialNumber;

    public function isManual()
    {
        return $this->manual;
    }

    public function setManual($manual)
    {
        $this->manual = $manual;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return true;
    }
}
