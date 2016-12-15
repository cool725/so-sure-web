<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\JudoPaymentRepository")
 * @Gedmo\Loggable
 */
class JudoPayment extends Payment
{
    const RESULT_SUCCESS = "Success";
    const RESULT_DECLINED = "Declined";
    const RESULT_SKIPPED = "Skipped";

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $result;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $message;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="4")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cardLastFour;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $barclaysReference;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $riskScore;

    public function getResult()
    {
        return $this->result;
    }

    public function setResult($result)
    {
        $this->result = $result;
        if ($result == self::RESULT_SUCCESS) {
            $this->setSuccess(true);
        } else {
            $this->setSuccess(false);
        }
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
        if ($this->success !== null) {
            return $this->success;
        }

        return $this->getResult() == self::RESULT_SUCCESS;
    }

    public function getRiskScore()
    {
        return $this->riskScore;
    }

    public function setRiskScore($riskScore)
    {
        $this->riskScore = $riskScore;
    }
}
