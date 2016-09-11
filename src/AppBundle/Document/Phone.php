<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Classes\Salva;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhoneRepository")
 */
class Phone
{
    use CurrencyTrait;

    const OS_CYANOGEN = 'Cyanogen';
    const OS_ANDROID = 'Android';
    const OS_IOS = 'iOS';
    const OS_FIRE = 'Fire';
    const OS_WINDOWS = 'Windows';
    const OS_BLACKBERRY = 'BlackBerry';

    const MONTHS_RETIREMENT = 48;

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
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $make;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $model;

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

    /** @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhonePrice") */
    protected $phonePrices = array();

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
     * @MongoDB\Date()
     */
    protected $releaseDate;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $active;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     */
    protected $suggestedReplacement;

    public function __construct()
    {
    }

    public function init(
        $make,
        $model,
        $premium,
        $memory = null,
        $devices = null,
        $initialPrice = null,
        $replacementPrice = null,
        $initialPriceUrl = null,
        \DateTime $date = null
    ) {
        if (!$date) {
            $date = new \DateTime();
        }
        $this->active = true;
        $this->make = $make;
        $this->model = $model;
        $this->devices = $devices;
        $this->memory = $memory;
        $this->initialPrice = strlen($initialPrice) > 0 ? $initialPrice : null;
        $this->replacementPrice = strlen($replacementPrice) > 0 ? $replacementPrice : null;
        $this->initialPriceUrl = strlen($initialPriceUrl) > 0 ? $initialPriceUrl : null;

        $phonePrice = $this->getCurrentPhonePrice();
        if (!$phonePrice) {
            $phonePrice = new PhonePrice();
            $phonePrice->setValidFrom($date);
            $this->addPhonePrice($phonePrice);
        }
        $phonePrice->setMonthlyPremiumPrice($premium);
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
        $this->os = strlen($os) > 0 ? $os : null;
        $this->initialOsVersion = strlen($initialOsVersion) > 0 ? $initialOsVersion : null;
        $this->upgradeOsVersion = strlen($upgradeOsVersion) > 0 ? $upgradeOsVersion : null;
        $this->processorSpeed = strlen($processorSpeed) > 0 ? $processorSpeed : null;
        $this->processorCores = strlen($processorCores) > 0 ? $processorCores : null;
        $this->ram = strlen($ram) > 0 ? $ram : null;
        $this->ssd = strlen($ssd) > 0 ? $ssd : null;
        $this->screenPhysical = strlen($screenPhysical) > 0 ? $screenPhysical : null;
        $this->screenResolutionWidth = strlen($screenResolutionWidth) > 0 ? $screenResolutionWidth : null;
        $this->screenResolutionHeight = strlen($screenResolutionHeight) > 0 ? $screenResolutionHeight : null;
        $this->camera = strlen($camera) > 0 ? $camera : null;
        $this->lte = strlen($lte) > 0 ? $lte : null;
        $this->releaseDate = is_object($releaseDate) ? $releaseDate : null;
    }

    public function getOs()
    {
        return $this->os;
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

    public function getMake()
    {
        return $this->make;
    }

    public function setMake($make)
    {
        $this->make = $make;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function getDevices()
    {
        return $this->devices;
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

    public function getInitialPrice()
    {
        return $this->toTwoDp($this->initialPrice);
    }

    public function getReplacementPrice()
    {
        return $this->toTwoDp($this->replacementPrice);
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

    public function getMonthAge()
    {
        if (!$this->getReleaseDate()) {
            return null;
        }

        $diff = $this->getReleaseDate()->diff(new \DateTime());
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

    public function policyProfit($claimFrequency)
    {
        $price = $this->getReplacementPrice();
        if (!$price && $this->getSuggestedReplacement()) {
            $price = $this->getSuggestedReplacement()->getReplacementPrice();
        }
        if (!$price) {
            return null;
        }

        // Avg Excess + Expected Recycling - Claims handling fee - Claims Check fee - replacement phone price
        $costOfClaims = 56 + 19 - 14 - 1 - $price;
        $nwt = $this->getCurrentPhonePrice()->getYearlyGwp() - Salva::YEARLY_TOTAL_COMMISSION;
        $profit = $nwt + $costOfClaims * $claimFrequency;

        return $this->toTopTwoDp($profit);
    }

    public function getCurrentPhonePrice(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        foreach ($this->getPhonePrices() as $phonePrice) {
            if ($phonePrice->getValidFrom() <= $date &&
                (!$phonePrice->getValidTo() || $phonePrice->getValidTo() > $date)) {
                return $phonePrice;
            }
        }

        return null;
    }

    public function isSameMake($make)
    {
        $make = strtolower($make);
        if ($make == 'lge') {
            $make = 'lg';
        } elseif ($make == 'google') {
            // https://en.wikipedia.org/wiki/Google_Nexus
            // TODO: Improve based on model, but as not really used at this point
            if (in_array($this->getMake(), ['LG', 'Huawei', 'Motorola'])) {
                $make = strtolower($this->getMake());
            }
        }

        return strtolower($this->getMake()) == $make;
    }

    public function __toString()
    {
        $name = sprintf("%s %s", $this->make, $this->model);
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
        ];
    }

    public function toEditArray()
    {
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'devices' => $this->getDevices(),
            'memory' => $this->getMemory(),
            'gwp' => $this->getCurrentPhonePrice()->getGwp(),
            'active' => $this->getActive(),
        ];
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
}
