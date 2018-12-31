<?php

namespace AppBundle\Document;

use AppBundle\Classes\SoSure;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
*/
class JudoPaymentMethod extends PaymentMethod
{
    use DateTrait;

    const DEVICE_DNA_NOT_PRESENT = 'device-dna-not-present';

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $customerToken;

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

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $deviceDna;

    public function setCustomerToken($customerToken)
    {
        $this->customerToken = $customerToken;
    }

    public function getCustomerToken()
    {
        return $this->customerToken;
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

    public function getDeviceDna()
    {
        return $this->deviceDna;
    }

    public function setDeviceDna($deviceDna)
    {
        $this->deviceDna = $deviceDna;
    }

    public function getDecodedDeviceDna()
    {
        if (!$this->deviceDna) {
            return null;
        }

        $data = null;
        try {
            $data = json_decode($this->deviceDna, true);

            return $data;
        } catch (\Exception $e) {
            return null;
        }
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
        // Receipts use cardLastfour whereas payments are cardLastFour
        if ($this->getCardDetail('cardLastfour')) {
            return $this->getCardDetail('cardLastfour');
        } else {
            return $this->getCardDetail('cardLastFour');
        }
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
        if ($cardType == 1) {
            return 'Visa';
        } elseif ($cardType == 2) {
            return 'Mastercard';
        } elseif ($cardType == 3) {
            return 'Visa Electron';
        } elseif ($cardType == 4) {
            return 'Switch';
        } elseif ($cardType == 5) {
            return 'Solo';
        } elseif ($cardType == 6) {
            return 'Laser';
        } elseif ($cardType == 7) {
            return 'China Union Pay';
        } elseif ($cardType == 8) {
            return 'Amex';
        } elseif ($cardType == 9) {
            return 'JCB';
        } elseif ($cardType == 10) {
            return 'Maestro';
        } elseif ($cardType == 11) {
            return 'Visa Debit';
        } elseif ($cardType == 12) {
            return 'Mastercard Debit';
        } elseif ($cardType == 13) {
            return 'Visa Purchasing';
        } elseif ($cardType == 0) {
            return 'Unknown';
        } else {
            return 'Missing';
        }
    }
}
