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
    // TODO: Remove - unused as too integrated
    const FEATURE_RENEWAL = 'renewal';
    const FEATURE_PICSURE = 'picsure';
    const FEATURE_SALVA_POLICY_UPDATE = 'salva-policy-update';

    // TODO: Verify if still used
    const FEATURE_QUOTE_LEAD = 'quote-lead';
    const FEATURE_STARLING = 'starling';

    // Active features
    const FEATURE_PAYMENT_PROBLEM_INTERCOM = 'payment-problem-intercom';
    const FEATURE_DAVIES_IMPORT_ERROR_EMAIL = 'davies-import-error-email';
    const FEATURE_BACS = 'bacs';
    const FEATURE_CARD_OPTION_WITH_BACS = 'card-option-with-bacs';
    const FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR = 'app-ios-imei-validation-colour';
    const FEATURE_APP_PICSURE_ACCELEROMETER = 'app-picsure-accelerometer';
    const FEATURE_APP_PICSURE_DOTCODE = 'app-picsure-dotcode';
    const FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION = 'app-facebook-userfriends-permission';
    const FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP = 'claims-default-direct-group';
    const FEATURE_RATE_LIMITING = 'rate-limiting';
    const FEATURE_PAYMENTS_BCC = 'bcc-payments';

    // All Features should be here
    public static $features = [
        self::FEATURE_QUOTE_LEAD,
        self::FEATURE_RENEWAL,
        self::FEATURE_PICSURE,
        self::FEATURE_PAYMENT_PROBLEM_INTERCOM,
        self::FEATURE_DAVIES_IMPORT_ERROR_EMAIL,
        self::FEATURE_STARLING,
        self::FEATURE_SALVA_POLICY_UPDATE,
        self::FEATURE_BACS,
        self::FEATURE_CARD_OPTION_WITH_BACS,
        self::FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR,
        self::FEATURE_APP_PICSURE_ACCELEROMETER,
        self::FEATURE_APP_PICSURE_DOTCODE,
        self::FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION,
        self::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP,
        self::FEATURE_RATE_LIMITING,
        self::FEATURE_PAYMENTS_BCC,
    ];

    // @codingStandardsIgnoreStart
    public static $descriptions = [
        self::FEATURE_QUOTE_LEAD => 'Display a save this quote w/email to user on quote page - unused?',
        self::FEATURE_RENEWAL => 'Create renewal policies - too integrated to turn off',
        self::FEATURE_PICSURE => 'pic-sure funcationlaity - too integrated to turn off',
        self::FEATURE_PAYMENT_PROBLEM_INTERCOM => 'Use intercom campaign to send payment errors to the user (1st time payment failure only)',
        self::FEATURE_DAVIES_IMPORT_ERROR_EMAIL => 'Notify davies of errors',
        self::FEATURE_STARLING => 'Starling integration - unused?',
        self::FEATURE_SALVA_POLICY_UPDATE => 'Use salva update api call instead of cancel/create',
        self::FEATURE_BACS => 'Bacs functionality - too integrated to turn off',
        self::FEATURE_CARD_OPTION_WITH_BACS => 'Allow users to also pay by card in web purchase flow',
        self::FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR => 'Ask Julien',
        self::FEATURE_APP_PICSURE_ACCELEROMETER => 'Ask Julien',
        self::FEATURE_APP_PICSURE_DOTCODE => 'Display dotcode on iOS for on the background image. Allows us to validate the imei in cases of suspected hacking.',
        self::FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION => 'Request user friends from Facebook. Requires permission from Facebook we lost in 2018 (but could re-request)',
        self::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP => 'Should direct group be the default claim handler for new claims. If changing update phone system as well.',
        self::FEATURE_RATE_LIMITING => 'Use rate limiting functionality for various items including recipero imei checks and policy creation.',
        self::FEATURE_PAYMENTS_BCC => 'Bcc payment failure emails (and related) to bcc@so-sure.com',
    ];
    // @codingStandardsIgnoreEnd

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
