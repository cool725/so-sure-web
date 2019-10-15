<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\PhoneExcess;
use AppBundle\Service\PostcodeService;
use AppBundle\Tests\Document\PhoneExcessTest;
use AppBundle\Exception\InvalidPriceStreamException;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhoneRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Phone
{
    use DateTrait;
    use CurrencyTrait;
    use ArrayToApiArrayTrait;

    const OS_CYANOGEN = 'Cyanogen';
    const OS_ANDROID = 'Android';
    const OS_IOS = 'iOS';
    const OS_FIRE = 'Fire';
    const OS_WINDOWS = 'Windows';
    const OS_BLACKBERRY = 'BlackBerry';

    const MONTHS_RETIREMENT = 48;

    const WARRANTY_LIKELY = 'likely';
    const WARRANTY_MAYBE = 'maybe';
    const WARRANTY_UNLIKELY = 'unlikely';

    public static $osTypes = [
        self::OS_CYANOGEN => self::OS_CYANOGEN,
        self::OS_ANDROID => self::OS_ANDROID,
        self::OS_IOS => self::OS_IOS,
        self::OS_FIRE => self::OS_FIRE,
        self::OS_WINDOWS => self::OS_WINDOWS,
        self::OS_BLACKBERRY => self::OS_BLACKBERRY,
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Regex(pattern="/^[a-zA-Z]+$/")  // must match quote_make_model_memory requirements
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $make;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $makeCanonical;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $alternativeMake;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $alternativeMakeCanonical;

    /**
     * @AppAssert\Token()
     * @Assert\Regex(pattern="/^[\+\-\.a-zA-Z0-9() ]+$/") // must match quote_make_model_memory requirements
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $model;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $modelCanonical;

    /**
     * @MongoDB\Field(type="collection")
     * @MongoDB\Index(unique=false)
     */
    protected $devices;

    /**
     * @Assert\Range(min=0,max=20000)
     * @MongoDB\Field(type="float")
     */
    protected $memory;

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhonePrice")
     */
    protected $phonePrices = [];

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhonePrice")
     */
    protected $annualPhonePrices = [];

    /**
     * List of the phone's retail prices over time. Not guaranteed to be in order.
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhoneRetailPrice")
     */
    protected $retailPrices = [];

    /**
     * @Assert\Range(min=0,max=2000)
     * @MongoDB\Field(type="float")
     */
    protected $initialPrice;

    /**
     * @Assert\Range(min=0,max=2000)
     * @MongoDB\Field(type="float")
     */
    protected $replacementPrice;

    /**
     * @Assert\Url(protocols = {"http", "https"})
     * @MongoDB\Field(type="string")
     */
    protected $initialPriceUrl;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $os;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $initialOsVersion;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $upgradeOsVersion;

    /**
     * @Assert\Range(min=0,max=20000)
     * @MongoDB\Field(type="int")
     */
    protected $processorSpeed;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="int")
     */
    protected $processorCores;

    /**
     * @Assert\Range(min=0,max=200000)
     * @MongoDB\Field(type="int")
     */
    protected $ram;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $ssd;

    /**
     * @Assert\Range(min=0,max=2000)
     * @MongoDB\Field(type="int")
     */
    protected $screenPhysical;

    /**
     * @Assert\Range(min=0,max=20000)
     * @MongoDB\Field(type="int")
     */
    protected $screenResolutionWidth;

    /**
     * @Assert\Range(min=0,max=20000)
     * @MongoDB\Field(type="int")
     */
    protected $screenResolutionHeight;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="int")
     */
    protected $camera;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $lte;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $releaseDate;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $active;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @var Phone
     */
    protected $suggestedReplacement;

    /**
     * @Assert\Url(protocols = {"http", "https"})
     * @MongoDB\Field(type="string")
     */
    protected $imageUrl;

    /**
     * @Assert\Length(min="1", max="3000")
     * @MongoDB\Field(type="string")
     */
    protected $description;

    /**
     * @Assert\Length(min="1", max="1000")
     * @MongoDB\Field(type="string")
     */
    protected $funFacts;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $highlight;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $topPhone;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $newHighDemand;

    /**
     * The offers that pertain to this phone.
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Offer")
     */
    protected $offers = [];

    /**
     * If set, then use this path for the canonical link
     *
     * @Assert\Length(min="1", max="200")
     * @MongoDB\Field(type="string")
     */
    protected $canonicalPath;

    public function __construct()
    {
    }

    public function init(
        $make,
        $model,
        $premium,
        PolicyTerms $policyTerms,
        $memory = null,
        $devices = null,
        $initialPrice = null,
        $replacementPrice = null,
        $initialPriceUrl = null,
        \DateTime $date = null
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $this->active = true;
        $this->setMake($make);
        $this->setModel($model);
        $this->devices = $devices;
        $this->memory = $memory;
        $this->initialPrice = mb_strlen($initialPrice) > 0 ? $initialPrice : null;
        $this->replacementPrice = mb_strlen($replacementPrice) > 0 ? $replacementPrice : null;
        $this->initialPriceUrl = mb_strlen($initialPriceUrl) > 0 ? $initialPriceUrl : null;

        if ($premium > 0) {
            $phonePrice = $this->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
            if (!$phonePrice) {
                $phonePrice = new PhonePrice();
                $phonePrice->setValidFrom($date);
                $this->addPhonePrice($phonePrice);
                $phonePrice->setExcess($policyTerms->getDefaultExcess());
                $phonePrice->setPicSureExcess($policyTerms->getDefaultPicSureExcess());
            }
            $phonePrice->setMonthlyPremiumPrice($premium, $date);
        }
    }

    public function setDetails(
        $os,
        $initialOsVersion,
        $upgradeOsVersion,
        $processorSpeed,
        $processorCores,
        $ram,
        $ssd,
        $screenPhysical,
        $screenResolutionWidth,
        $screenResolutionHeight,
        $camera,
        $lte,
        $releaseDate
    ) {
        $this->os = mb_strlen($os) > 0 ? $os : null;
        $this->initialOsVersion = mb_strlen($initialOsVersion) > 0 ? $initialOsVersion : null;
        $this->upgradeOsVersion = mb_strlen($upgradeOsVersion) > 0 ? $upgradeOsVersion : null;
        $this->processorSpeed = mb_strlen($processorSpeed) > 0 ? $processorSpeed : null;
        $this->processorCores = mb_strlen($processorCores) > 0 ? $processorCores : null;
        $this->ram = mb_strlen($ram) > 0 ? $ram : null;
        $this->ssd = mb_strlen($ssd) > 0 ? $ssd : null;
        $this->screenPhysical = mb_strlen($screenPhysical) > 0 ? $screenPhysical : null;
        $this->screenResolutionWidth = mb_strlen($screenResolutionWidth) > 0 ? $screenResolutionWidth : null;
        $this->screenResolutionHeight = mb_strlen($screenResolutionHeight) > 0 ? $screenResolutionHeight : null;
        $this->camera = mb_strlen($camera) > 0 ? $camera : null;
        $this->lte = mb_strlen($lte) > 0 ? $lte : null;
        $this->releaseDate = is_object($releaseDate) ? $releaseDate : null;
    }

    public function getOs()
    {
        return $this->os;
    }

    public function setOs($os)
    {
        $this->os = $os;
    }

    public function getInitialOsVersion()
    {
        return $this->initialOsVersion;
    }

    public function getUpgradeOsVersion()
    {
        return $this->upgradeOsVersion;
    }

    public function getOsString()
    {
        if ($this->getUpgradeOsVersion()) {
            return sprintf("%s %s (%s)", $this->getOs(), $this->getInitialOsVersion(), $this->getUpgradeOsVersion());
        } else {
            return sprintf("%s %s", $this->getOs(), $this->getInitialOsVersion());
        }
    }

    public function getProcessorSpeed()
    {
        return $this->processorSpeed;
    }

    public function getProcessorCores()
    {
        return $this->processorCores;
    }

    public function getRam()
    {
        return $this->ram;
    }

    public function getRamDisplay()
    {
        $ram = $this->getRam();
        if ($ram < 1024) {
            return sprintf('%d MB', $ram);
        }
        if ($ram % 1024 == 0) {
            return sprintf('%d GB', $ram / 1024);
        }

        return sprintf('%0.1f GB', $ram / 1024);
    }

    public function getScreenPhysical()
    {
        return $this->screenPhysical;
    }

    public function getScreenPhysicalInch()
    {
        return round($this->getScreenPhysical() / 25.4, 1);
    }

    public function getScreenResolutionWidth()
    {
        return $this->screenResolutionWidth;
    }

    public function getScreenResolutionHeight()
    {
        return $this->screenResolutionHeight;
    }

    public function getScreenResolution()
    {
        return sprintf("%d x %d", $this->getScreenResolutionWidth(), $this->getScreenResolutionHeight());
    }

    public function getSsd()
    {
        return $this->ssd;
    }

    public function getCamera()
    {
        return $this->camera;
    }

    public function getLte()
    {
        return $this->lte;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getMake()
    {
        return $this->make;
    }

    public function setMake($make)
    {
        $this->make = $make;
        $this->setMakeCanonical($make);
    }

    public function getMakeCanonical()
    {
        return $this->makeCanonical;
    }

    public function setMakeCanonical($make)
    {
        $this->makeCanonical = mb_strtolower($make);
    }

    public function getAlternativeMake()
    {
        return $this->alternativeMake;
    }

    public function setAlternativeMake($alternativeMake)
    {
        $this->alternativeMake = $alternativeMake;
        $this->setAlternativeMakeCanonical($alternativeMake);
    }

    public function getAlternativeMakeCanonical()
    {
        return $this->alternativeMakeCanonical;
    }

    public function setAlternativeMakeCanonical($alternativeMake)
    {
        $this->alternativeMakeCanonical = mb_strtolower($alternativeMake);
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getModelCanonical()
    {
        return $this->modelCanonical;
    }

    public function getEncodedModel()
    {
        $model = str_replace('+', '-plus', $this->getModel());

        return str_replace(' ', '+', $model);
    }

    public function getEncodedModelCanonical()
    {
        $model = str_replace('+', '-plus', $this->getModelCanonical());

        return str_replace(' ', '-', $model);
    }

    /**
      * TODO: Adjust cdn images to use encodedModel instead
      */
    public function getImageEncodedModel()
    {
        $model = str_replace('+', '-Plus', $this->getModel());

        return str_replace(' ', '-', $model);
    }

    public static function decodeModel($encodedModel)
    {
        $decodedModel = str_replace(['+','-'], ' ', $encodedModel);
        $decodedModel = str_replace('-Plus', '+', $decodedModel);
        $decodedModel = str_replace('-plus', '+', $decodedModel);

        return $decodedModel;
    }

    public static function decodedModelHyph($encodedModel)
    {
        $decodedModel = str_replace(['+','-'], ' ', $encodedModel);
        $decodedModel = str_replace(' Plus', '+', $decodedModel);
        $decodedModel = str_replace(' plus', '+', $decodedModel);

        return $decodedModel;
    }

    public function setModel($model)
    {
        if (mb_stripos($model, '-plus') !== false) {
            throw new \Exception(sprintf('%s contains -Plus which will break encoding rules', $model));
        }

        $this->model = $model;
        $this->setModelCanonical($model);
    }

    public function setModelCanonical($model)
    {
        if (mb_stripos($model, '-plus') !== false) {
            throw new \Exception(sprintf('%s contains -Plus which will break encoding rules', $model));
        }

        $this->modelCanonical = mb_strtolower($model);
    }

    public function isSameMakeModelCanonical($make, $model)
    {
        return $this->getMakeCanonical() == $make && $this->getEncodedModelCanonical() == $model;
    }

    public function getSearchQuerystring()
    {
        return sprintf('%s %s %sGB', $this->getMake(), $this->getEncodedModel(), $this->getMemory());
    }

    public function getName()
    {
        return sprintf('%s %s', $this->getMake(), $this->getModel());
    }

    public function getDevices()
    {
        return $this->devices;
    }

    public function getDevicesAsUpper()
    {
        $devices = [];
        foreach ($this->getDevices() as $device) {
            $devices[] = mb_strtoupper($device);
        }

        return $devices;
    }

    public function setDevices($devices)
    {
        $this->devices = $devices;
    }

    public function getMemory()
    {
        return $this->memory;
    }

    public function setMemory($memory)
    {
        $this->memory = $memory;
    }

    public function getPhonePrices()
    {
        return $this->phonePrices;
    }

    public function addPhonePrice(PhonePrice $phonePrice)
    {
        $this->phonePrices[] = $phonePrice;
    }

    public function getAnnualPhonePrices()
    {
        return $this->annualPhonePrices;
    }

    public function addAnnualPhonePrice(PhonePrice $phonePrice)
    {
        $this->annualPhonePrices[] = $phonePrice;
    }

    public function getInitialPrice()
    {
        return $this->toTwoDp($this->initialPrice);
    }

    public function setInitialPrice($price)
    {
        $this->initialPrice = $price;
    }

    public function getReplacementPrice()
    {
        return $this->toTwoDp($this->replacementPrice);
    }

    /**
     * Adds an offer into the phone's list of offers and sets a pointer to this phone on the offer.
     * @param Offer $offer is the offer to add.
     */
    public function addOffer($offer)
    {
        $this->offers[] = $offer;
        $offer->setPhone($this);
    }

    /**
     * Gives a list of all offers associated with this phone.
     * @return array containing all offers.
     */
    public function getOffers()
    {
        if (is_array($this->offers)) {
            return $this->offers;
        }
        return $this->offers->toArray();
    }

    public function getActiveOffers()
    {
        foreach ($this->getOffers() as $offer) {
            if ($offer->getActive()) {
                yield $offer;
            }
        }
    }

    public function getInactiveOffers()
    {
        foreach ($this->getOffers() as $offer) {
            if (!$offer->getActive()) {
                yield $offer;
            }
        }
    }

    /**
     * Adds a retail price to the phone's list of retail prices.
     * @param float     $price is the actual price value.
     * @param string    $url   is a link to proof of this price.
     * @param \DateTime $date  is the date at which this becomes valid.
     */
    public function addRetailPrice($price, $url, $date)
    {
        $retailPrice = new PhoneRetailPrice();
        $retailPrice->setPrice($price);
        $retailPrice->setUrl($url);
        $retailPrice->setDate($date);
        $this->retailPrices[] = $retailPrice;
    }

    /**
     * Returns the list of the phone's retail prices in arbitrary order.
     * @return array of all the phone retail prices.
     */
    public function getRetailPrices()
    {
        return $this->retailPrices;
    }

    /**
     * Gives you all of the phone's retail prices that are or have been in effect.
     * @param \DateTime $date is the date to be considered as now.
     * @return array of retail prices.
     */
    public function getPastRetailPrices($date)
    {
        $retailPrices = $this->getRetailPrices();
        if (!is_array($retailPrices)) {
            $retailPrices = $retailPrices->toArray();
        }
        return array_filter($retailPrices, function ($price) use ($date) {
            return $price->getDate() <= $date;
        });
    }

    /**
     * Gives you either the current retail price if there is one or the initial price.
     * @param \DateTime|null $date is the date by which to find which retail price is current. If null is given it will
     *                             default to the current time and date.
     * @return float the most up to date retail price stored.
     */
    public function getCurrentRetailPrice(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime("now", new \DateTimeZone(SoSure::TIMEZONE));
        }
        $retailPrices = $this->getPastRetailPrices($date);
        if (count($retailPrices) == 0) {
            return $this->getInitialPrice();
        }
        usort($retailPrices, function ($a, $b) {
            return ($a->getDate() < $b->getDate()) ? 1 : -1;
        });
        return $retailPrices[0]->getPrice();
    }

    public function getReplacementPriceOrSuggestedReplacementPrice()
    {
        return $this->getReplacementPrice() ?
                $this->getReplacementPrice() :
                $this->getSuggestedReplacement()->getReplacementPrice();
    }

    public function getReleaseDate()
    {
        return $this->releaseDate;
    }

    /**
     * @return Phone
     */
    public function getSuggestedReplacement()
    {
        return $this->suggestedReplacement;
    }

    public function setSuggestedReplacement($suggestedReplacement)
    {
        $this->suggestedReplacement = $suggestedReplacement;
    }

    public function getActive()
    {
        return $this->active;
    }

    public function setActive($active)
    {
        $this->active = $active;
    }

    public function getImageUrl()
    {
        return $this->imageUrl;
    }

    public function setImageUrl($imageUrl)
    {
        $this->imageUrl = $imageUrl;
    }

    public function getImageUrlWithFallback()
    {
        if ($this->getImageUrl()) {
            return $this->getImageUrl();
        }

        return sprintf(
            'https://cdn.so-sure.com/images/library_phones/%s/%s.png',
            $this->getMake(),
            $this->getImageEncodedModel()
        );
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getFunFacts()
    {
        return $this->funFacts;
    }

    public function setFunFacts($funFacts)
    {
        $this->funFacts = $funFacts;
    }

    public function isHighlight()
    {
        return $this->highlight;
    }

    public function setHighlight($highlight)
    {
        $this->highlight = $highlight;
    }

    public function isTopPhone()
    {
        if (null === $this->topPhone) {
            $this->topPhone = false;
        }

        return $this->topPhone;
    }

    public function setTopPhone($topPhone)
    {
        $this->topPhone = $topPhone;
    }

    public function isNewHighDemand()
    {
        return $this->newHighDemand;
    }

    public function setNewHighDemand($newHighDemand)
    {
        $this->newHighDemand = $newHighDemand;
    }

    public function setCanonicalPath($canonicalPath)
    {
        $this->canonicalPath = $canonicalPath;
    }

    public function getCanonicalPath()
    {
        return $this->canonicalPath;
    }

    public function getMonthAge()
    {
        if (!$this->getReleaseDate()) {
            return null;
        }

        $diff = $this->getReleaseDate()->diff(\DateTime::createFromFormat('U', time()));
        return $diff->y * 12 + $diff->m;
    }

    public function shouldBeRetired()
    {
        if ($this->getMonthAge() > self::MONTHS_RETIREMENT) {
            return true;
        } else {
            return false;
        }
    }

    public function getSalvaMiniumumBinderMonthlyPremium()
    {
        if (!$this->getSalvaBinderMonthlyPremium()) {
            return null;
        }

        return $this->toTwoDp(0.67 * $this->getSalvaBinderMonthlyPremium());
    }

    public function getSalvaBinderMonthlyPremium(\DateTime $date = null)
    {
        $binder2016 = new \DateTime('2016-09-01 00:00:00', SoSure::getSoSureTimezone());
        $binder2018 = new \DateTime('2018-01-01 00:00:00', SoSure::getSoSureTimezone());
        if (!$date) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
        }
        if ($date >= Salva::getSalvaBinderEndDate()) {
            throw new \Exception('No binder available');
        }
        $price = $this->getCurrentRetailPrice($date);
        if ($price <= 150) {
            return 3.99 + 1.5; // 5.49
        } elseif ($price <= 250) {
            return 4.99 + 1.5; // 6.49
        } elseif ($price <= 400) {
            return 5.49 + 1.5; // 6.99
        } elseif ($price <= 500) {
            return 5.99 + 1.5; // 7.49
        } elseif ($price <= 600) {
            return 6.99 + 1.5; // 8.49
        } elseif ($price <= 750) {
            return 7.99 + 1.5; // 9.49
        } elseif ($price <= 1000) {
            return 8.99 + 1.5; // 10.49
        }
        if ($date >= $binder2018) {
            if ($price <= 1250) {
                return 9.99 + 1.5; // 11.49
            } elseif ($price <= 1500) {
                return 10.99 + 1.5; // 12.49
            }
        }
        return null;
    }

    /**
     * Gives you the amount of profit that policies on this phone should yield under some assumed variables.
     * @param string $stream         is the price stream we are calculating this for.
     * @param float  $claimFrequency is the assumed frequency of claims.
     * @param float  $consumerPayout is an assumed amount removed from earned money after underwriter commission.
     * @param float  $iptRebate      is the assumed amount of IPT rebated.
     * @return float|null the amount of profit that there should be per policy on this phone under the given
     *                    assumptions or null if it cannot be calculated.
     */
    public function policyProfit($stream, $claimFrequency, $consumerPayout, $iptRebate)
    {
        $price = $this->getReplacementPrice();
        if (!$price && $this->getSuggestedReplacement()) {
            $price = $this->getSuggestedReplacement()->getReplacementPrice();
        }
        if (!$price || $price == 0) {
            return null;
        }
        // Avg Excess + Expected Recycling - Claims handling fee - Claims Check fee - replacement phone price
        $netCostOfClaims = 56 + 19 - 14 - 1 - $price;
        /** @var PhonePrice $price */
        $price = $this->getCurrentPhonePrice($stream);
        if (!$price) {
            return null;
        }
        $uwReceived = $price->getYearlyGwp() - Salva::YEARLY_COVERHOLDER_COMMISSION;
        $nwp = $uwReceived - $consumerPayout;
        $uwPrefReturn = ($nwp * 0.08);
        $profit = $nwp + $iptRebate + ($netCostOfClaims * $claimFrequency) - $uwPrefReturn;
        return $this->toTopTwoDp($profit);
    }

    /**
     * Gives you all of the phone's prices in the given stream in descending order of when they become valid.
     * @param string $stream is the stream of prices we are trying to get.
     * @return array of the prices.
     */
    public function getOrderedPhonePrices($stream)
    {
        $prices = $this->getPhonePrices();
        if (!is_array($prices)) {
            $prices = $prices->toArray();
        }
        usort($prices, function ($a, $b) {
            $aValid = $a->getValidFrom();
            $bValid = $b->getValidFrom();
            if ($aValid < $bValid) {
                return 1;
            } elseif ($aValid > $bValid) {
                return -1;
            }
            return 0;
        });
        return array_values(array_filter($prices, function ($price) use ($stream) {
            return $price->inStream($stream);
        }));
    }

    /**
     * Returns the price that is current.
     * @param string         $stream is the price stream that we want the current price for.
     * @param \DateTime|null $date    the date at which the price should be current. Null for now.
     * @return PhonePrice|null the found price or null if there is no price current at that time.
     */
    public function getCurrentPhonePrice($stream, \DateTime $date = null)
    {
        if ($stream == PhonePrice::STREAM_ALL) {
            throw new InvalidPriceStreamException("Can't get current price occupying all streams");
        }
        $date = $date ?: new \DateTime();
        foreach ($this->getOrderedPhonePrices($stream) as $price) {
            if ($price->getValidFrom() <= $date) {
                return $price;
            }
        }
        return null;
    }

    /**
     * Gets all the phone prices that are current in different streams and then returns the one that is the cheapest.
     * @param \DateTime|null $date is the date at which the prices should be current or null for current time.
     * @return PhonePrice|null the lowest current phone price or null if no phone prices are current at all.
     */
    public function getLowestCurrentPhonePrice(\DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $prices = [];
        foreach (PhonePrice::STREAMS as $stream) {
            $price = $this->getCurrentPhonePrice($stream, $date);
            if ($price) {
                $prices[] = $price;
            }
        }
        if (count($prices) == 0) {
            return null;
        }
        usort($prices, function ($a, $b) {
            return ($a->getGwp() < $b->getGwp()) ? -1 : 1;
        });
        return $prices[0];
    }

    /**
     * Gets all the phone prices that are current in different streams and then returns the one that is the oldest.
     * @param \DateTime|null $date is the date at which the prices should be current or null to use the current time.
     * @return PhonePrice|null the oldest current phone price or null if there are no current prices.
     */
    public function getOldestCurrentPhonePrice(\DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $prices = [];
        foreach (PhonePrice::STREAMS as $stream) {
            $price = $this->getCurrentPhonePrice($stream, $date);
            if ($price) {
                $prices[] = $price;
            }
        }
        if (count($prices) == 0) {
            return null;
        }
        usort($prices, function ($a, $b) {
            return ($a->getValidFrom() < $b->getValidFrom()) ? -1 : 1;
        });
        return $prices[0];
    }

    /**
     * Gives a list of all phone prices that have been current in the past but are not any more. The list will be in
     * order from newest to oldest.
     * @param \DateTime|null $date    is the date at which we are checking.
     * @return array of matching phone prices.
     */
    public function getPreviousPhonePrices(\DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $previous = [];
        $old = false;
        foreach ($this->getOrderedPhonePrices(PhonePrice::STREAM_ANY) as $price) {
            if ($old) {
                $previous[] = $price;
            } elseif ($price->getValidFrom() <= $date) {
                $old = true;
            }
        }
        return $previous;
    }

    /**
     * Gives all phone prices that are yet in the future.
     * @param \DateTime|null $date    is the date which is to be considered the present.
     * @return array containing the prices in descending order of date.
     */
    public function getFuturePhonePrices(\DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $future = [];
        foreach ($this->getOrderedPhonePrices(PhonePrice::STREAM_ANY) as $price) {
            if ($price->getValidFrom() > $date) {
                $future[] = $price;
            }
        }
        return $future;
    }

    /**
     * Gives a list of all phone prices that have been valid since the given number of minutes from right now.
     * @param string    $stream  is the price stream that we want the recent prices for.
     * @param int       $minutes is the number of minutes deviance within which to find the prices.
     * @param \DateTime $date    is the date from which the prices must be recent.
     * @return array with all the prices within it in descending order of date.
     */
    public function getRecentPhonePrices($stream, int $minutes, \DateTime $date = null)
    {
        if ($stream == PhonePrice::STREAM_ALL) {
            throw new InvalidPriceStreamException("Can't get recent prices occupying all streams");
        }
        $date = $date ?: new \DateTime();
        $line = (clone $date)->sub(new \DateInterval(sprintf('PT%dM', $minutes)));
        $recent = [];
        foreach (PhonePrice::subStreams($stream) as $subStream) {
            $end = $date;
            foreach ($this->getOrderedPhonePrices($subStream) as $price) {
                $validFrom = $price->getValidFrom();
                if ($end >= $line && $validFrom < $date) {
                    $end = $validFrom;
                    if (!in_array($price, $recent)) {
                        $recent[] = $price;
                    }
                }
            }
        }
        usort($recent, function ($a, $b) {
            return $a->getValidFrom() < $b->getValidFrom() ? 1 : -1;
        });
        return $recent;
    }

    public function getPreviousPhonePricesAsString(\DateTime $date = null)
    {
        return $this->getPhonePricesAsString($this->getPreviousPhonePrices($date));
    }

    public function getFuturePhonePricesAsString(\DateTime $date = null)
    {
        return $this->getPhonePricesAsString($this->getFuturePhonePrices($date));
    }

    public function getPhonePricesAsString($prices)
    {
        $lines = ['Assumes current IPT Rate!'];
        foreach ($prices as $price) {
            $lines[] = sprintf(
                "%s @ £%.2f - %s",
                $price->getValidFrom()->format(\DateTime::ATOM),
                $price->getMonthlyPremiumPrice(),
                $price->getStream()
            );
        }
        return implode(PHP_EOL, $lines);
    }

    public function isSameMake($make)
    {
        $make = mb_strtolower($make);
        if ($make == 'lge') {
            $make = 'lg';
        }

        return mb_strtolower($this->getMake()) == $make || mb_strtolower($this->getAlternativeMake()) == $make;
    }

    public function isAppAvailable()
    {
        return in_array($this->getOs(), [self::OS_ANDROID, self::OS_CYANOGEN, self::OS_IOS]);
    }

    public function isApple()
    {
        return $this->getMake() == 'Apple';
    }

    public function isITunes()
    {
        return $this->getOs() == self::OS_IOS;
    }

    public function isGooglePlay()
    {
        return in_array($this->getOs(), [self::OS_ANDROID, self::OS_CYANOGEN]);
    }

    public function getMakeWithAlternative()
    {
        $make = $this->getMake();
        if ($this->getAlternativeMake()) {
            $make = sprintf("%s/%s", $this->getMake(), $this->getAlternativeMake());
        }

        return $make;
    }

    public function __toString()
    {
        $name = sprintf("%s %s", $this->getMakeWithAlternative(), $this->getModel());
        if ($this->memory) {
            $name = sprintf("%s (%s GB)", $name, $this->getMemory());
        }

        return $name;
    }

    public function getNameFormSafe()
    {
        $name = $this->getName();
        if ($this->memory) {
            $name = sprintf("%s (%s GB)", $name, $this->getMemory());
        }

        return $name;
    }

    public function getModelMemory()
    {
        $name = sprintf("%s", $this->model);
        if ($this->memory) {
            $name = sprintf("%s (%s GB)", $name, $this->memory);
        }

        return $name;
    }

    public function toApiArray()
    {
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'devices' => $this->getDevices(),
            'memory' => $this->getMemory(),
            'image_url' => $this->getImageUrl() ? $this->getImageUrl() : null,
            // TODO: migrate to store link in db and use branch
            'quote_url' => sprintf('https://wearesosure.com/quote/%s', $this->getId()),
        ];
    }

    public function toPriceArray(\DateTime $date = null)
    {
        // sort out phone price list.
        $apiPrices = [];
        $prices = $this->getPhonePrices();
        foreach ($prices as $price) {
            $priceArray = $price->toPriceArray($date);
            $priceArray["stream"] = $price->getStream();
            $apiPrices[] = $priceArray;
        }
        // send all of the things.
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'devices' => $this->getDevices(),
            'memory' => $this->getMemory(),
            'gwp' => $this->getLowestCurrentPhonePrice() ? $this->getLowestCurrentPhonePrice()->getGwp() : null,
            'active' => $this->getActive(),
            'prices' => $apiPrices
        ];
    }

    public function toDetailsArray()
    {
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'memory' => $this->getMemory(),
            'description' => $this->getDescription(),
            'funFacts' => $this->getFunFacts(),
            'canonicalPath' => $this->getCanonicalPath(),
        ];
    }

    public function asQuoteApiArray(PostcodeService $postcodeService, User $user = null)
    {
        $currentPhonePrice = $this->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
        if (!$currentPhonePrice) {
            if ($this->getActive()) {
                return null;
            }
            return [
                'phone' => $this->toApiArray(),
                'can_purchase' => $this->getActive(),
                'monthly_premium' => null,
                'monthly_loss' => null,
                'yearly_premium' => null,
                'yearly_loss' => null,
                'connection_value' => null,
                'max_connections' => null,
                'max_pot' => null,
                'valid_to' => null,
            ];
        }

        // If there is an end date, then quote should be valid until then
        $quoteValidTo = (new \DateTime())->add(new \DateInterval('P1D'));

        $promoAddition = 0;
        $isPromoLaunch = false;

        $monthlyPremium = $currentPhonePrice->getMonthlyPremiumPrice();
        if ($user && !$user->allowedMonthlyPayments($postcodeService)) {
            $monthlyPremium = null;
        }
        $yearlyPremium = $currentPhonePrice->getYearlyPremiumPrice();
        if ($user && !$user->allowedYearlyPayments()) {
            $yearlyPremium = null;
        }

        return [
            'monthly_premium' => $monthlyPremium,
            'monthly_loss' => 0,
            'yearly_premium' => $yearlyPremium,
            'yearly_loss' => 0,
            'phone' => $this->toApiArray(),
            'connection_value' => $currentPhonePrice->getInitialConnectionValue($promoAddition),
            'max_connections' => $currentPhonePrice->getMaxConnections($promoAddition, $isPromoLaunch),
            'max_pot' => $currentPhonePrice->getMaxPot($isPromoLaunch),
            'valid_to' => $quoteValidTo->format(\DateTime::ATOM),
            'can_purchase' => $this->getActive(),
            'excesses' => $currentPhonePrice->getExcess() ?
                $currentPhonePrice->getExcess()->toApiArray() :
                [],
            'picsure_excesses' => $currentPhonePrice->getPicSureExcess() ?
                $currentPhonePrice->getPicSureExcess()->toApiArray() :
                [],
        ];
    }

    /**
     * Makes an array of offers.
     * @return array containing the offers TODO: be more specific.
     */
    public function toOfferArray()
    {
    }

    public function toAlternativeArray()
    {
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'memory' => $this->getMemory(),
            'name' => $this->__toString(),
            'os' => $this->getOsString(),
            'replacement_price' => $this->getReplacementPrice(),
            'processor_speed' => $this->getProcessorSpeed(),
            'processor_cores' => $this->getProcessorCores(),
            'ram' => $this->getRam(),
            'ssd' => $this->getSsd(),
            'screen_physical_inch' => $this->getScreenPhysicalInch(),
            'screen_physical' => $this->getScreenPhysical(),
            'screen_resolution' => $this->getScreenResolution(),
            'camera' => $this->getCamera(),
            'lte' => $this->getLte(),
            'age' => $this->getMonthAge(),
            'initial_price' => $this->getInitialPrice(),
        ];
    }

    public function getWarrantyMonths()
    {
        if ($this->getMake() == "Alcatel") {
            return 12;
        } elseif ($this->getMake() == "Apple") {
            return 12;
        } elseif ($this->getMake() == "BlackBerry") {
            return 12;
        } elseif ($this->getMake() == "Doro") {
            return 12;
        } elseif ($this->getMake() == "HTC") {
            return 24;
        } elseif ($this->getMake() == "Huawei") {
            return 24;
        } elseif ($this->getMake() == "LG") {
            return 24;
        } elseif ($this->getMake() == "Motorola") {
            return 12;
        } elseif ($this->getMake() == "Microsoft") {
            return 12;
        } elseif ($this->getMake() == "Nokia") {
            return 12;
        } elseif ($this->getMake() == "Samsung") {
            return 24;
        } elseif ($this->getMake() == "Sony") {
            return 24;
        } else {
            return null;
        }
    }

    public function isUnderWarranty()
    {
        $now = \DateTime::createFromFormat('U', time());
        if ($now < $this->getWarrantyEarlist()) {
            return self::WARRANTY_LIKELY;
        } elseif ($now < $this->getWarrantyLatest()) {
            return self::WARRANTY_MAYBE;
        } else {
            return self::WARRANTY_UNLIKELY;
        }
    }

    public function getWarrantyEarlist()
    {
        if (!$this->getReleaseDate()) {
            return null;
        }
        if (!$months = $this->getWarrantyMonths()) {
            return null;
        }

        $cutoff = clone $this->getReleaseDate();
        $cutoff = $cutoff->add(new \DateInterval(sprintf('P%dM', $months)));

        return $cutoff;
    }

    public function getWarrantyLatest()
    {
        if (!$end = $this->getWarrantyEarlist()) {
            return null;
        }
        $end = clone $end;
        $end = $end->add(new \DateInterval(sprintf('P6M')));

        return $end;
    }

    public function getComparisions()
    {
        $comparision = [];
        if ($this->__toString() == "Apple iPhone 7 (32 GB)") {
            $comparision = [
                'Networks' => 154,
                'High Street' => 169.99,
                'Protect Your Bubble' => 95.88
            ];
        } elseif ($this->__toString() == "Apple iPhone 7 Plus (32 GB)") {
            $comparision = [
                'Networks' => 154,
                'High Street' => 169.99,
                'Protect Your Bubble' => 101.88
            ];
        } elseif ($this->__toString() == "Apple iPhone 6S (32 GB)") {
            $comparision = [
                'Networks' => 154,
                'High Street' => 169.99,
                'Protect Your Bubble' => 95.88
            ];
        } elseif ($this->__toString() == "Apple iPhone 6S (16 GB)") {
            $comparision = [
                'Networks' => 154,
                'High Street' => 169.99,
                'Protect Your Bubble' => 95.88
            ];
        } elseif ($this->__toString() == "Samsung Galaxy S7 edge (32 GB)") {
            $comparision = [
                'Networks' => 154,
                'High Street' => 169.99,
                'Protect Your Bubble' => 119.88
            ];
        } elseif ($this->__toString() == "Samsung Galaxy S7 (32 GB)") {
            $comparision = [
                'Networks' => 154,
                'High Street' => 169.99,
                'Protect Your Bubble' => 119.88
            ];
        }

        return $comparision;
    }

    public function getMaxComparision()
    {
        $yearlyPrice = $this->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
        if (!$yearlyPrice) {
            return null;
        }
        /** @var PhonePrice */
        $yearlyPrice = $yearlyPrice;
        $maxComparision = $yearlyPrice->getYearlyPremiumPrice();
        $comparision = $this->getComparisions();
        if (count($comparision) > 0) {
            $maxComparision = 0;
            foreach ($comparision as $name => $value) {
                if ($value > $maxComparision) {
                    $maxComparision = $value;
                }
            }
        }
        return $maxComparision;
    }

    public function changePrice(
        $gwp,
        \DateTime $from,
        PhoneExcess $excess,
        PhoneExcess $picSureExcess,
        $notes = null,
        $stream = null,
        \DateTime $date = null
    ) {
        if (!$date) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
        }
        // dates must be in the future
        if ($from < $date) {
            throw new \Exception(sprintf(
                '%s must be after %s',
                $from->format(\DateTime::ATOM),
                $date->format(\DateTime::ATOM)
            ));
        }
        if (!$this->getSalvaMiniumumBinderMonthlyPremium()) {
            throw new \Exception(sprintf('Unable to determine min binder'));
        }
        $oneDay = $this->addBusinessDays($date, 1);
        $dateDiff = $oneDay->diff($from);
        if ($dateDiff->invert) {
            throw new \Exception(sprintf(
                '%s must be at least 1 business day (%s) after now',
                $from->format(\DateTime::ATOM),
                $oneDay->format(\DateTime::ATOM)
            ));
        }
        $price = new PhonePrice();
        $price->setGwp($gwp);
        $price->setValidFrom($from);
        $price->setNotes($notes);
        if ($stream) {
            $price->setStream($stream);
        }
        if ($price->getMonthlyPremiumPrice(null, $from) < $this->getSalvaMiniumumBinderMonthlyPremium()) {
            throw new \Exception(sprintf(
                '£%.2f is less than allowed min binder £%.2f',
                $price->getMonthlyPremiumPrice(null, $from),
                $this->getSalvaMiniumumBinderMonthlyPremium()
            ));
        }
        if ($this->getOldestCurrentPhonePrice()) {
            if ($this->getOldestCurrentPhonePrice()->getValidFrom() > $from) {
                throw new \Exception(sprintf(
                    '%s must be after current pricing start date %s',
                    $from->format(\DateTime::ATOM),
                    $this->getOldestCurrentPhonePrice()->getValidFrom()->format(\DateTime::ATOM)
                ));
            }
        }
        $price->setExcess($excess);
        $price->setPicSureExcess($picSureExcess);
        $this->addPhonePrice($price);
    }
}
