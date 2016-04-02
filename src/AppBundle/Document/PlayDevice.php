<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class PlayDevice
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\Field(type="string", name="retail_branding") */
    protected $retailBranding;

    /** @MongoDB\Field(type="string", name="marketing_name") */
    protected $marketingName;

    /** @MongoDB\Field(type="string") */
    protected $device;

    /** @MongoDB\Field(type="string") */
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
