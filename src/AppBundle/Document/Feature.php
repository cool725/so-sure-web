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
    const FEATURE_PAYMENT_PROBLEM_INTERCOM = 'payment-problem-intercom';
    const FEATURE_DAVIES_IMPORT_ERROR_EMAIL = 'davies-import-error-email';
    const FEATURE_STARLING = 'starling';
    const FEATURE_SALVA_POLICY_UPDATE = 'salva-policy-update';
    const FEATURE_BACS = 'bacs';
    const FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR = 'app-ios-imei-validation-colour';
    const FEATURE_APP_PICSURE_ACCELEROMETER = 'app-picsure-accelerometer';
    const FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION = 'app-facebook-userfriends-permission';
    const FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP = 'claims-default-direct-group';

    public static $features = [
        self::FEATURE_QUOTE_LEAD,
        self::FEATURE_RENEWAL,
        self::FEATURE_PICSURE,
        self::FEATURE_PAYMENT_PROBLEM_INTERCOM,
        self::FEATURE_DAVIES_IMPORT_ERROR_EMAIL,
        self::FEATURE_STARLING,
        self::FEATURE_SALVA_POLICY_UPDATE,
        self::FEATURE_BACS,
        self::FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR,
        self::FEATURE_APP_PICSURE_ACCELEROMETER,
        self::FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION,
        self::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP,
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
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
        $this->created = \DateTime::createFromFormat('U', time());
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
