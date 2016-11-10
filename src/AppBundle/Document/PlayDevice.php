<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @MongoDB\Index(keys={"retailBranding"="asc", "marketingName"="asc", "model"="asc", "device"="asc"},
 *  sparse="true", unique="true")
 */
class PlayDevice
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="150")
     * @MongoDB\Field(type="string")
     */
    protected $retailBranding;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="150")
     * @MongoDB\Field(type="string")
     */
    protected $marketingName;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=false, sparse=true)
     */
    protected $device;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $model;

    public function __construct()
    {
    }

    public function init(
        $retailBranding,
        $marketingName,
        $device,
        $model
    ) {
        $this->retailBranding = $retailBranding;
        $this->marketingName = $marketingName;
        $this->device = $device;
        $this->model = $model;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getRetailBranding()
    {
        return $this->retailBranding;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getDevice()
    {
        return $this->device;
    }

    public function getMarketingName()
    {
        return $this->marketingName;
    }
}
