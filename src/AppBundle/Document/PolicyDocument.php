<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"keyfacts"="PolicyKeyFacts", "terms"="PolicyTerms"})
 */
class PolicyDocument
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\Field(type="boolean") */
    protected $latest;

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

    public function toApiArray($viewUrl)
    {
        return [
            'id' => $this->getId(),
            'view_url' => $viewUrl,
        ];
    }
}
