<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 */
class Feature
{
    const FEATURE_QUOTE_LEAD = 'quote-lead';
    const FEATURE_RENEWAL = 'renewal';
    const FEATURE_PICSURE = 'picsure';

    public static $features = [
        self::FEATURE_QUOTE_LEAD,
        self::FEATURE_RENEWAL,
        self::FEATURE_PICSURE,
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $created;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="250")
     * @MongoDB\Field(type="string")
     */
    protected $description;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $enabled;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
    }

    public function toApiArray()
    {
        return [
            'name' => $this->getName(),
            'enabled' => $this->isEnabled() ? true : false,
        ];
    }
}
