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
     * @MongoDB\String()
     * @Gedmo\Versioned
     */
    protected $result;

    /**
     * @MongoDB\String()
     * @Gedmo\Versioned
     */
    protected $message;

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

    public function isSuccess()
    {
        return $this->getResult() == self::RESULT_SUCCESS;
    }
}
