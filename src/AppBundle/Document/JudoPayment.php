<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class JudoPayment extends Payment
{
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
}
