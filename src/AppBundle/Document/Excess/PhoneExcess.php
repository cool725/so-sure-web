<?php

namespace AppBundle\Document\Excess;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Subvariant;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhoneExcess extends Excess
{
    use CurrencyTrait;

    /**
     * @Assert\Range(min=0,max=250)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $damage;

    /**
     * @Assert\Range(min=0,max=250)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $warranty;

    /**
     * @Assert\Range(min=0,max=250)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $extendedWarranty;

    /**
     * @Assert\Range(min=0,max=250)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $loss;

    /**
     * @Assert\Range(min=0,max=250)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $theft;

    public function getDamage()
    {
        return $this->damage;
    }

    public function setDamage($damage)
    {
        $this->damage = $damage;
    }

    public function getWarranty()
    {
        return $this->warranty;
    }

    public function setWarranty($warranty)
    {
        $this->warranty = $warranty;
    }

    public function getExtendedWarranty()
    {
        return $this->extendedWarranty;
    }

    public function setExtendedWarranty($extendedWarranty)
    {
        $this->extendedWarranty = $extendedWarranty;
    }

    public function getLoss()
    {
        return $this->loss;
    }

    public function setLoss($loss)
    {
        $this->loss = $loss;
    }

    public function getTheft()
    {
        return $this->theft;
    }

    public function setTheft($theft)
    {
        $this->theft = $theft;
    }

    public function getValue($type)
    {
        switch ($type) {
            case Claim::TYPE_DAMAGE:
                return $this->getDamage();
            case Claim::TYPE_WARRANTY:
                return $this->getWarranty();
            case Claim::TYPE_EXTENDED_WARRANTY:
                return $this->getExtendedWarranty();
            case Claim::TYPE_LOSS:
                return $this->getLoss();
            case Claim::TYPE_THEFT:
                return $this->getTheft();
            default:
                throw new \Exception(sprintf("Unknown Claim Type %s", $type));
        }
    }

    /**
     * Gives you the lowest type of excess's value.
     * @return float the lowest excess.
     */
    public function getMin()
    {
        return min(
            $this->getDamage(),
            $this->getWarranty(),
            $this->getExtendedWarranty(),
            $this->getLoss(),
            $this->getTheft()
        );
    }

    /**
     * Gives you the highest type of excess's value.
     * @return float the highest excess.
     */
    public function getMax()
    {
        return max(
            $this->getDamage(),
            $this->getWarranty(),
            $this->getExtendedWarranty(),
            $this->getLoss(),
            $this->getTheft()
        );
    }

    public function __toString()
    {
        return sprintf(
            '£%0.0f Damage / £%0.0f Theft / £%0.0f Loss',
            $this->getDamage(),
            $this->getTheft(),
            $this->getLoss()
        );
    }

    public function shortDescription()
    {
        return sprintf(
            '%0.0f / %0.0f',
            $this->getDamage(),
            $this->getTheft()
        );
    }

    /**
     * @param Subvariant|null $subvariant
     * @return array[]
     */
    public function toApiArray(Subvariant $subvariant = null)
    {
        $excessElements = [
            [
                'type' => Claim::TYPE_LOSS,
                'display' => 'Loss',
                'amount' => $this->toTwoDp($this->getLoss()),
            ],
            [
                'type' => Claim::TYPE_THEFT,
                'display' => 'Theft',
                'amount' => $this->toTwoDp($this->getTheft()),
            ],
            [
                'type' => Claim::TYPE_DAMAGE,
                'display' => 'Accidental Damage',
                'amount' => $this->toTwoDp($this->getDamage()),
            ],
            [
                'type' => Claim::TYPE_WARRANTY,
                'display' => 'Warranty',
                'amount' => $this->toTwoDp($this->getWarranty()),
            ],
            [
                'type' => Claim::TYPE_EXTENDED_WARRANTY,
                'display' => 'Breakdown',
                'amount' => $this->toTwoDp($this->getExtendedWarranty()),
            ],
        ];

        if (null === $subvariant) {
            return $excessElements;
        }

        if ($subvariant instanceof Subvariant) {
            $subArray = [
                [
                    'name' => 'Loss',
                    'active' => $subvariant->getLoss()
                ],
                [
                    'name' => 'Theft',
                    'active' => $subvariant->getTheft()
                ],
                [
                    'name' => 'Accidental Damage',
                    'active' => $subvariant->getDamage()
                ],
                [
                    'name' => 'Warranty',
                    'active' => $subvariant->getWarranty()
                ],
                [
                    'name' => 'Breakdown',
                    'active' => $subvariant->getExtendedWarranty()
                ]
            ];
            $excessEl = [];
            foreach ($subArray as $sub) {
                $excessEl[] = $this->filterArrayByKeyValue($excessElements, $sub);
            }

            return array_values(array_filter($excessEl));
        }
        return null;
    }

    private function filterArrayByKeyValue($array, $sub)
    {
        foreach ($array as $key => $value) {
            if ($value['display'] == $sub['name'] && $sub['active'] === true) {
                return $value;
            }
        }
    }

    public function toPriceArray()
    {
        return [
            'loss' => $this->toTwoDp($this->getLoss()),
            'theft' => $this->toTwoDp($this->getTheft()),
            'warranty' => $this->toTwoDp($this->getWarranty()),
            'extendedWarranty' => $this->toTwoDp($this->getExtendedWarranty()),
            'damage' => $this->toTwoDp($this->getDamage()),
            'detail' => $this->shortDescription(),
        ];
    }

    public function equal(PhoneExcess $excess = null)
    {
        if (!$excess) {
            return false;
        }

        return $this->areEqualToTwoDp($this->getTheft(), $excess->getTheft()) &&
            $this->areEqualToTwoDp($this->getLoss(), $excess->getLoss()) &&
            $this->areEqualToTwoDp($this->getDamage(), $excess->getDamage()) &&
            $this->areEqualToTwoDp($this->getWarranty(), $excess->getWarranty()) &&
            $this->areEqualToTwoDp($this->getExtendedWarranty(), $excess->getExtendedWarranty());
    }
}
