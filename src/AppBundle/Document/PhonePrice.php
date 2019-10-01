<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\PhoneExcess;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhonePrice extends Price
{
    use CurrencyTrait;

    const STREAM_MONTHLY = 'monthly';
    const STREAM_YEARLY = 'yearly';
    const STREAM_ALL = 'all';
    const STREAM_ANY = 'any';
    const STREAMS = [
        self::STREAM_MONTHLY,
        self::STREAM_YEARLY,
        self::STREAM_ALL
    ];

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\PhoneExcess")
     * @Gedmo\Versioned
     * @var PhoneExcess|null
     */
    protected $picSureExcess;

    /**
     * @Assert\Choice(choices=PhonePrice::STREAMS)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $stream;

    /**
     * Creates a phone price and sets it to default to all channels.
     */
    public function __construct()
    {
        parent::__construct();
        $this->stream = self::STREAM_ALL;
    }

    public function setPicSureExcess(PhoneExcess $phoneExcess = null)
    {
        $this->picSureExcess = $phoneExcess;
    }

    /**
     * @return PhoneExcess|null
     */
    public function getPicSureExcess()
    {
        return $this->picSureExcess;
    }

    /**
     * Sets this price's stream.
     * @param string $stream is the stream to set it to.
     */
    public function setStream($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Gives you the price's current stream value.
     * @return string the stream.
     */
    public function getStream()
    {
        return $this->stream;
    }

    public function getMaxPot($isPromoLaunch = false)
    {
        if ($isPromoLaunch) {
            return $this->toTwoDp($this->getYearlyPremiumPrice());
        } else {
            return $this->toTwoDp($this->getYearlyPremiumPrice() * 0.8);
        }
    }

    public function getMaxConnections($promoAddition = 0, $isPromoLaunch = false)
    {
        return (int) ceil($this->getMaxPot($isPromoLaunch) / $this->getInitialConnectionValue($promoAddition));
    }

    public function getInitialConnectionValue($promoAddition = 0)
    {
        return PhonePolicy::STANDARD_VALUE + $promoAddition;
    }

    public function createPremium($additionalGwp = null, \DateTime $date = null)
    {
        $premium = new PhonePremium();
        $this->populatePremium($premium, $additionalGwp, $date);
        if ($this->getPicSureExcess()) {
            $premium->setPicSureExcess($this->getPicSureExcess());
        }

        return $premium;
    }

    /**
     * Tells you if this price is in the given stream or set of streams.
     * @param string $stream is the stream we are checking if this price is in. Calling this with STREAM_ALL does not
     *                       make any sense unless you are specifically looking for prices that are in all streams
     *                       for some reason.
     * @return boolean true if it is in the stream, and false if not.
     */
    public function inStream($stream)
    {
        return $this->getStream() == $stream || $this->getStream() == self::STREAM_ALL ||
            $stream == self::STREAM_ANY;
    }

    public function toPriceArray(\DateTime $date = null)
    {
        return array_merge(parent::toPriceArray($date), [
            'picsure_excess' => $this->getPicSureExcess() ? $this->getPicSureExcess()->toApiArray() : null,
            'picsure_excess_detail' =>
                $this->getPicSureExcess() ? $this->getPicSureExcess()->toPriceArray()['detail'] : '??',
        ]);
    }
}
