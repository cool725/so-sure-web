<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Phone
{
    use CurrencyTrait;

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\Field(type="string") */
    protected $make;

    /** @MongoDB\Field(type="string") */
    protected $model;

    /** @MongoDB\Field(type="collection") @MongoDB\Index(unique=false) */
    protected $devices;

    /** @MongoDB\Field(type="float", nullable=true) */
    protected $memory;

    /** @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhonePremium") */
    protected $policyPremiums = array();

    public function __construct()
    {
    }

    public function init(
        $make,
        $model,
        $policyPrice,
        $lossPrice,
        $memory = null,
        $devices = null
    ) {
        $this->make = $make;
        $this->model = $model;
        $this->devices = $devices;
        $this->memory = $memory;

        $policyPremium = $this->getCurrentPolicyPremium();
        if (!$policyPremium) {
            $policyPremium = new PhonePremium();
            $policyPremium->setValidFrom(new \DateTime());
            $this->addPolicyPremium($policyPremium);
        }
        $policyPremium->setPolicyPrice($policyPrice);
        $policyPremium->setLossPrice($lossPrice);
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

    public function getPolicyPremiums()
    {
        return $this->policyPremiums;
    }

    public function addPolicyPremium($policyPremium)
    {
        $this->policyPremiums[] = $policyPremium;
    }

    public function getCurrentPolicyPremium(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        foreach ($this->getPolicyPremiums() as $policyPremium) {
            if ($policyPremium->getValidFrom() <= $date &&
                (!$policyPremium->getValidTo() || $policyPremium->getValidTo() > $date)) {
                return $policyPremium;
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
            'policy_price' => $this->getPolicyPrice(),
            'loss_price' => $this->getLossPrice(),
        ];
    }
}
