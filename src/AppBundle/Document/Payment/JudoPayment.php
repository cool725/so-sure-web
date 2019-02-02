<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\JudoPaymentRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class JudoPayment extends Payment
{
    const RESULT_SUCCESS = "Success";
    const RESULT_DECLINED = "Declined";
    const RESULT_SKIPPED = "Skipped";
    const RESULT_ERROR = "Error";

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

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $webType;

    public function getResult()
    {
        return $this->result;
    }

    public function setResult($result)
    {
        $this->result = $result;
        if ($result == self::RESULT_SUCCESS) {
            $this->setSuccess(true);
        } elseif (in_array($result, [self::RESULT_DECLINED, self::RESULT_SKIPPED, self::RESULT_ERROR])) {
            $this->setSuccess(false);
        } else {
            throw new \Exception(sprintf('Unknown result %s', $result));
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

    public function getWebType()
    {
        return $this->webType;
    }

    public function setWebType($webType)
    {
        $this->webType = $webType;
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

    public function isUserPayment()
    {
        return true;
    }

    /**
     * Judopay specific logic for whether to show a payment to users.
     * @inheritDoc
     */
    public function isVisibleUserPayment()
    {
        if ($this->areEqualToTwoDp(0, $this->amount)) {
            return false;
        }

        return $this->success;
    }

    /**
     * Gives the name that this payment should be called by to users when there is not an overriding circumstance.
     * @inheritDoc
     */
    protected function userPaymentName()
    {
        return "Card Payment";
    }
}
