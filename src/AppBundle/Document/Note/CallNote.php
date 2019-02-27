<?php

namespace AppBundle\Document\Note;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument()
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class CallNote extends Note
{
    const RESULT_NO_ANSWER = 'No answer';
    const RESULT_WILL_PAY = 'Will pay';
    const RESULT_NOT_CONNECTED = 'Not connected';
    const RESULT_HANG_UP = 'Hang up';
    const RESULT_BAD_DEBT = 'Does not want to pay';
    const RESULT_CANCELLED = 'UNPAID - CANCELLED';
    const RESULT_CANCELLATION = 'DISPOSSESSION - CANCELLED';
    const RESULT_WRONG_NUMBER = 'Wrong number';
    const RESULT_PAYMENT_CHANGED = 'Submitted';
    const RESULT_REQUEST_CANCEL = 'Requested to cancel';
    const RESULT_PAID = 'Paid';
    const RESULT_DISPOSSESSION = 'DISPOSSESSION';
    const RESULT_UPGRADE = 'UPGRADE';
    const RESULT_LOST = 'Lost';

    const CATEGORY_NO_ANSWER = 'No Answer';
    const CATEGORY_WILL_PAY = 'Will Pay';
    const CATEGORY_DISCONNECT_LINE = 'Disconnect line';
    const CATEGORY_HANG_UP = 'Hang up';
    const CATEGORY_BAD_DEBT = 'Bad debt';
    const CATEGORY_LOST = 'Lost';
    const CATEGORY_CANCELLATION = 'Cancellation';
    const CATEGORY_PAYMENT_CHANGED = 'Payment changed';

    public static $results = [
        self::RESULT_NO_ANSWER => self::RESULT_NO_ANSWER,
        self::RESULT_WILL_PAY => self::RESULT_WILL_PAY,
        self::RESULT_NOT_CONNECTED => self::RESULT_NOT_CONNECTED,
        self::RESULT_HANG_UP => self::RESULT_HANG_UP,
        self::RESULT_BAD_DEBT => self::RESULT_BAD_DEBT,
        self::RESULT_CANCELLED => self::RESULT_CANCELLED,
        self::RESULT_CANCELLATION => self::RESULT_CANCELLATION,
        self::RESULT_WRONG_NUMBER => self::RESULT_WRONG_NUMBER,
        self::RESULT_PAYMENT_CHANGED => self::RESULT_PAYMENT_CHANGED,
        self::RESULT_REQUEST_CANCEL => self::RESULT_REQUEST_CANCEL,
        self::RESULT_PAID => self::RESULT_PAID,
        self::RESULT_DISPOSSESSION => self::RESULT_DISPOSSESSION,
        self::RESULT_UPGRADE => self::RESULT_UPGRADE,
        self::RESULT_LOST => self::RESULT_LOST,
    ];

    public static $categories = [
        self::RESULT_NO_ANSWER => self::CATEGORY_NO_ANSWER,
        self::RESULT_WILL_PAY => self::CATEGORY_WILL_PAY,
        self::RESULT_NOT_CONNECTED => self::CATEGORY_DISCONNECT_LINE,
        self::RESULT_HANG_UP => self::CATEGORY_HANG_UP,
        self::RESULT_BAD_DEBT => self::CATEGORY_BAD_DEBT,
        self::RESULT_CANCELLED => self::CATEGORY_LOST,
        self::RESULT_CANCELLATION => self::CATEGORY_CANCELLATION,
        self::RESULT_WRONG_NUMBER => self::CATEGORY_DISCONNECT_LINE,
        self::RESULT_PAYMENT_CHANGED => self::CATEGORY_PAYMENT_CHANGED,
        self::RESULT_REQUEST_CANCEL => self::CATEGORY_CANCELLATION,
        self::RESULT_PAID => self::CATEGORY_PAYMENT_CHANGED,
        self::RESULT_DISPOSSESSION => self::CATEGORY_CANCELLATION,
        self::RESULT_UPGRADE => self::CATEGORY_PAYMENT_CHANGED,
        self::RESULT_LOST => self::CATEGORY_LOST,
    ];

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $result;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $voicemail;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $emailed;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $sms;

    public function getResult()
    {
        return $this->result;
    }

    public function getCategory()
    {
        if (isset(CallNote::$categories[$this->getResult()])) {
            return CallNote::$categories[$this->getResult()];
        }

        return null;
    }

    public function setResult($result)
    {
        $this->result = $result;
    }

    public function getVoicemail()
    {
        return $this->voicemail;
    }

    public function setVoicemail($voicemail)
    {
        $this->voicemail = $voicemail;
    }

    public function setEmailed($emailed)
    {
        $this->emailed = $emailed;
    }

    public function getEmailed()
    {
        return $this->emailed;
    }

    public function getSms()
    {
        return $this->sms;
    }

    public function setSms($sms)
    {
        $this->sms = $sms;
    }

    public function getActions($csv = false)
    {
        $actions = [];
        if ($this->getVoicemail()) {
            $actions[] = 'Voicemail';
        }
        if ($this->getEmailed()) {
            $actions[] = 'Emailed';
        }
        if ($this->getSms()) {
            $actions[] = 'Sms';
        }

        if ($csv) {
            if ($this->getVoicemail()) {
                return sprintf('Voicemail%s', $this->getOtherActions());
            } else {
                return $this->getOtherActions();
            }
        } else {
            return implode(',', $actions);
        }
    }

    public function getOtherActions()
    {
        $actions = [];
        if ($this->getEmailed()) {
            $actions[] = 'Email';
        }
        if ($this->getSms()) {
            $actions[] = 'Sms';
        }

        return implode(' + ', $actions);
    }
}
