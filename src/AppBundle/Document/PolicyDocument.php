<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"terms"="PolicyTerms"})
 */
class PolicyDocument
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $latest;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $aggregator;

    /** @MongoDB\Field(type="string") */
    protected $version;

    public function __construct()
    {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getLatest()
    {
        return $this->latest;
    }

    public function setLatest($latest)
    {
        $this->latest = $latest;
    }

    public function getAggregator()
    {
        return $this->aggregator;
    }

    public function setAggregator($aggregator)
    {
        $this->aggregator = $aggregator;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function setVersion($version)
    {
        $this->version = $version;
    }

    public function toApiArray($viewUrl)
    {
        return [
            'id' => $this->getId(),
            'view_url' => $viewUrl,
            'version' => $this->getVersion()
        ];
    }
}
