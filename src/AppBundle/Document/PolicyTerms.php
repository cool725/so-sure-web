<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class PolicyTerms
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
