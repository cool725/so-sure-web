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
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $make;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $alternativeMake;

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

    /**
     * @Assert\Url(protocols = {"http", "https"})
     * @MongoDB\Field(type="string")
     */
    protected $imageUrl;

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
        $phonePrice->setMonthlyPremiumPrice($premium, $date);
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

    public function getAlternativeMake()
    {
        return $this->alternativeMake;
    }

    public function setAlternativeMake($alternativeMake)
    {
        $this->alternativeMake = $alternativeMake;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getEncodedModel()
    {
        $model = str_replace('+', '-Plus', $this->getModel());

        return str_replace(' ', '+', $model);
    }

    public static function decodeModel($encodedModel)
    {
        $decodedModel = str_replace('+', ' ', $encodedModel);
        $decodedModel = str_replace('-Plus', '+', $decodedModel);

        return $decodedModel;
    }

    public function setModel($model)
    {
        if (stripos($model, '-Plus') !== false) {
            throw new \Exception(sprintf('%s contains -Plus which will break encoding rules', $model));
        }

        $this->model = $model;
    }

    public function getDevices()
    {
        return $this->devices;
    }

    public function getDevicesAsUpper()
    {
        $devices = [];
        foreach ($this->getDevices() as $device) {
            $devices[] = strtoupper($device);
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

    public function getImageUrl()
    {
        return $this->imageUrl;
    }

    public function setImageUrl($imageUrl)
    {
        $this->imageUrl = $imageUrl;
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

    public function getSalvaMiniumumBinderMonthlyPremium()
    {
        return $this->toTwoDp(0.67 * $this->getSalvaBinderMonthlyPremium());
    }

    public function getSalvaBinderMonthlyPremium()
    {
        if ($this->getInitialPrice() <= 150) {
            return 3.99 + 1.5;
        } elseif ($this->getInitialPrice() <= 250) {
            return 4.99 + 1.5;
        } elseif ($this->getInitialPrice() <= 400) {
            return 5.49 + 1.5;
        } elseif ($this->getInitialPrice() <= 500) {
            return 5.99 + 1.5;
        } elseif ($this->getInitialPrice() <= 600) {
            return 6.99 + 1.5;
        } elseif ($this->getInitialPrice() <= 750) {
            return 7.99 + 1.5;
        } elseif ($this->getInitialPrice() <= 1000) {
            return 8.99 + 1.5;
        } else {
            throw new \Exception('Unknown binder pricing');
        }
    }

    public function policyProfit($claimFrequency, $consumerPayout, $iptRebate)
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

        $uwReceived = $this->getCurrentPhonePrice()->getYearlyGwp() - Salva::YEARLY_COVERHOLDER_COMMISSION;
        $nwp = $uwReceived - $consumerPayout;
        $uwPrefReturn = ($nwp * 0.08);

        $profit = $nwp + $iptRebate + ($netCostOfClaims * $claimFrequency) - $uwPrefReturn;

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

    public function getPreviousPhonePrices(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $previous = [];

        foreach ($this->getPhonePrices() as $phonePrice) {
            if ($phonePrice->getValidTo() && $phonePrice->getValidTo() <= $date) {
                $previous[] = $phonePrice;
            }
        }

        return $previous;
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
                "%s - %s @ £%.2f",
                $price->getValidFrom()->format(\DateTime::ATOM),
                $price->getValidTo() ? $price->getValidTo()->format(\DateTime::ATOM) : '...',
                $price->getMonthlyPremiumPrice()
            );
        }

        return implode(PHP_EOL, $lines);
    }

    public function getFuturePhonePrices(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $future = [];

        foreach ($this->getPhonePrices() as $phonePrice) {
            if ($phonePrice->getValidFrom() >= $date) {
                $future[] = $phonePrice;
            }
        }

        return $future;
    }

    public function isSameMake($make)
    {
        $make = strtolower($make);
        if ($make == 'lge') {
            $make = 'lg';
        }

        return strtolower($this->getMake()) == $make || strtolower($this->getAlternativeMake()) == $make;
    }

    public function isAppAvailable()
    {
        return in_array($this->getOs(), [self::OS_ANDROID, self::OS_CYANOGEN, self::OS_IOS]);
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
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'devices' => $this->getDevices(),
            'memory' => $this->getMemory(),
            'gwp' => $this->getCurrentPhonePrice()->getGwp(),
            'active' => $this->getActive(),
            'prices' => $this->eachApiArray($this->getPhonePrices(), $date),
        ];
    }

    public function asQuoteApiArray(User $user = null)
    {
        $currentPhonePrice = $this->getCurrentPhonePrice();
        if (!$currentPhonePrice) {
            return null;
        }

        // If there is an end date, then quote should be valid until then
        $quoteValidTo = $currentPhonePrice->getValidTo();
        if (!$quoteValidTo) {
            $quoteValidTo = new \DateTime();
            $quoteValidTo->add(new \DateInterval('P1D'));
        }

        $promoAddition = 0;
        $isPromoLaunch = false;

        $monthlyPremium = $currentPhonePrice->getMonthlyPremiumPrice();
        if ($user && !$user->allowedMonthlyPayments()) {
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
        $now = new \DateTime();
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
        $maxComparision = $this->getCurrentPhonePrice()->getYearlyPremiumPrice();
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
}
