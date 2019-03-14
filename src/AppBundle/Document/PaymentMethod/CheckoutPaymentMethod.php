<?php

namespace AppBundle\Document\PaymentMethod;

use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
*/
class CheckoutPaymentMethod extends PaymentMethod
{
    use DateTrait;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $customerId;

    /**
     * @MongoDB\Field(type="hash")
     */
    protected $cardTokens = array();

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cardToken;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cardTokenHash;

    public function setCustomerId($customerId)
    {
        $this->customerId = $customerId;
    }

    public function getCustomerId()
    {
        return $this->customerId;
    }

    public function addCardToken($key, $json)
    {
        $this->cardToken = $key;
        $this->cardTokens[$key] = $json;
        $this->setCardTokenHash(md5(serialize($json)));
    }

    public function addCardTokenArray($key, $array)
    {
        $this->addCardToken($key, json_encode($array));
    }

    public function getCardToken()
    {
        return $this->cardToken;
    }

    public function getCardTokens()
    {
        return $this->cardTokens;
    }

    public function hasCardTokens()
    {
        return count($this->getCardTokens()) > 0;
    }

    public function setCardTokenHash($cardTokenHash)
    {
        $this->cardTokenHash = $cardTokenHash;
    }

    public function getCardTokenHash()
    {
        return $this->cardTokenHash;
    }

    public function getCardDetail($type)
    {
        if (!isset($this->getCardTokens()[$this->getCardToken()])) {
            return null;
        }

        $json = $this->getCardTokens()[$this->getCardToken()];
        $data = null;
        try {
            $data = json_decode($json, true);

            if (!isset($data[$type])) {
                return null;
            }

            return $data[$type];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getCardLastFour()
    {
        return $this->getCardDetail('cardLastFour');
    }

    public function getCardEndDate()
    {
        return $this->getCardDetail('endDate');
    }

    public function getCardEndDateAsDate()
    {
        $end = $this->getCardEndDate();
        if (!$end) {
            return null;
        }

        $date = new \DateTime(
            sprintf('20%s-%s-01', mb_substr($end, 2, 2), mb_substr($end, 0, 2)),
            SoSure::getSoSureTimezone()
        );

        $date = $this->endOfMonth($date);

        return $date;
    }

    public function isValid()
    {
        return !$this->isCardExpired();
    }

    public function isCardExpired(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $end = $this->getCardEndDateAsDate();
        if (!$end) {
            return true;
        }

        return $date >= $end;
    }

    public function __toString()
    {
        return sprintf("%s **** %s (Exp: %s)", $this->getCardType(), $this->getCardLastFour(), $this->getCardEndDate());
    }

    public function getCardType($cardType = null)
    {
        if (!$cardType) {
            $cardType = $this->getCardDetail('cardType');
        }

        // see https://www.judopay.com/docs/v4_1/restful-api/api-reference/#transactionspayments-response-ok200
        if ($cardType) {
            return $cardType;
        } else {
            return 'Missing';
        }
    }

    public function toApiArray()
    {
        return [
            'type' => $this->getCardType(),
            'end_date' => $this->getCardEndDate(),
            'last_four' => $this->getCardLastFour(),
        ];
    }

    public static function emptyApiArray()
    {
        $paymentMethod = new CheckoutPaymentMethod();

        return $paymentMethod->toApiArray();
    }
}
