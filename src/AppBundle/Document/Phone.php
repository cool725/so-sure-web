<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Classes\Salva;

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

    /** @MongoDB\Field(type="string") */
    protected $make;

    /** @MongoDB\Field(type="string") */
    protected $model;

    /**
     * @MongoDB\Field(type="collection")
     * @MongoDB\Index(unique=false)
     */
    protected $devices;

    /** @MongoDB\Field(type="float") */
    protected $memory;

    /** @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhonePrice") */
    protected $phonePrices = array();

    /** @MongoDB\Field(type="float") */
    protected $initialPrice;

    /** @MongoDB\Field(type="float") */
    protected $replacementPrice;

    /** @MongoDB\Field(type="string") */
    protected $initialPriceUrl;

    /** @MongoDB\Field(type="string") */
    protected $os;

    /** @MongoDB\Field(type="string") */
    protected $initialOsVersion;

    /** @MongoDB\Field(type="string") */
    protected $upgradeOsVersion;

    /** @MongoDB\Field(type="int") */
    protected $processorSpeed;

    /** @MongoDB\Field(type="int") */
    protected $processorCores;

    /** @MongoDB\Field(type="int") */
    protected $ram;

    /** @MongoDB\Field(type="boolean") */
    protected $ssd;

    /** @MongoDB\Field(type="int") */
    protected $screenPhysical;

    /** @MongoDB\Field(type="int") */
    protected $screenResolutionWidth;

    /** @MongoDB\Field(type="int") */
    protected $screenResolutionHeight;

    /** @MongoDB\Field(type="int") */
    protected $camera;

    /** @MongoDB\Field(type="boolean") */
    protected $lte;

    /** @MongoDB\Date() */
    protected $releaseDate;

    /** @MongoDB\Field(type="boolean") */
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
        $initialPriceUrl = null
    ) {
        $this->active = true;
        $this->make = $make;
        $this->model = $model;
        $this->devices = $devices;
        $this->memory = $memory;
        $this->initialPrice = $initialPrice;
        $this->replacementPrice = $replacementPrice;
        $this->initialPriceUrl = $initialPriceUrl;

        $phonePrice = $this->getCurrentPhonePrice();
        if (!$phonePrice) {
            $phonePrice = new PhonePrice();
            $phonePrice->setValidFrom(new \DateTime());
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
        $this->os = $os;
        $this->initialOsVersion = $initialOsVersion;
        $this->upgradeOsVersion = $upgradeOsVersion;
        $this->processorSpeed = $processorSpeed;
        $this->processorCores = $processorCores;
        $this->ram = $ram;
        $this->ssd = $ssd;
        $this->screenPhysical = $screenPhysical;
        $this->screenResolutionWidth = $screenResolutionWidth;
        $this->screenResolutionHeight = $screenResolutionHeight;
        $this->camera = $camera;
        $this->lte = $lte;
        $this->releaseDate = $releaseDate;
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
        $nwt = $this->getCurrentPhonePrice()->getYearlyGwp() - Salva::YEARLY_BROKER_FEE;
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
