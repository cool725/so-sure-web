<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\JudoPaymentRepository")
 * @Gedmo\Loggable
 */
class JudoPayment extends Payment
{
    const RESULT_SUCCESS = "Success";
    const RESULT_DECLINED = "Declined";

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $result;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $message;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cardLastFour;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $barclaysReference;

    public function getResult()
    {
        return $this->result;
    }

    public function setResult($result)
    {
        $this->result = $result;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function setMessage($message)
    {
        $this->message = $message;
    }

    public function getCardLastFour()
    {
        return $this->cardLastFour;
    }

    public function setCardLastFour($cardLastFour)
    {
        $this->cardLastFour = $cardLastFour;
    }

    public function getBarclaysReference()
    {
        return $this->barclaysReference;
    }

    public function setBarclaysReference($barclaysReference)
    {
        $this->barclaysReference = $barclaysReference;
    }

    public function isSuccess()
    {
        return $this->getResult() == self::RESULT_SUCCESS;
    }
}
