<?php

namespace AppBundle\Document\Payment;

use AppBundle\Classes\NoOp;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\CheckoutPaymentRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class CheckoutPayment extends Payment
{
    const RESULT_AUTHORIZED = "Authorised";
    const RESULT_AUTHORIZED_3D = "Authorised 3-D";
    const RESULT_CARD_VERIFIED = 'Card Verified';
    const RESULT_CAPTURED = "Captured";
    const RESULT_REFUNDED = "Refunded";
    const RESULT_CANCELLED = "Cancelled";
    const RESULT_CHARGEBACK = "Chargeback";
    const RESULT_DECLINED = "Declined";
    const RESULT_DEFERRED = "Deferred Refund";
    const RESULT_EXPIRED = "Expired";
    const RESULT_PENDING = "Pending";
    const RESULT_TIMEOUT = "Timeout";
    const RESULT_VOID = "Voided";

    const RESULT_SKIPPED = "Skipped";

    // TODO
    const TYPE_PAYMENT = 'Payment';
    // TODO
    const TYPE_REFUND = 'Refund';

    const RESPONSE_CODE_SUCCESS = 10000;
    const RESPONSE_CODE_DECLINED = 20005;
    const RESPONSE_CODE_NO_FUNDS = 20051;

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
        if (in_array($result, [self::RESULT_CAPTURED, self::RESULT_REFUNDED, self::RESULT_CARD_VERIFIED])) {
            $this->setSuccess(true);
        } elseif (in_array($result, [
            self::RESULT_AUTHORIZED,
            self::RESULT_AUTHORIZED_3D,
            self::RESULT_PENDING,
            self::RESULT_DEFERRED,
            self::RESULT_TIMEOUT,
        ])) {
            // we don't have a defined success/failure yet
            NoOp::ignore([]);
        } elseif (in_array($result, [
            self::RESULT_DECLINED,
            self::RESULT_SKIPPED,
            self::RESULT_EXPIRED,
            self::RESULT_VOID,
            self::RESULT_CANCELLED
        ])) {
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

    public function isSuccess($includeAuthorized = false)
    {
        if ($this->success !== null) {
            return $this->success;
        }

        if ($includeAuthorized && $this->result == self::RESULT_AUTHORIZED) {
            return true;
        }

        return in_array($this->result, [self::RESULT_CAPTURED, self::RESULT_REFUNDED, self::RESULT_CARD_VERIFIED]);
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
        if ($this->amount < 0) {
            return "Card Refund";
        } else {
            return "Card Payment";
        }
    }

    public static function isSuccessfulResult($status, $includeAuthorized = false)
    {
        $checkout = new CheckoutPayment();
        $checkout->setResult($status);

        return $checkout->isSuccess($includeAuthorized);
    }
}
