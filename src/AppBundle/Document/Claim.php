<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\Excess;
use AppBundle\Document\File\ProofOfLossFile;
use AppBundle\Exception\ClaimException;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Document\File\S3ClaimFile;
use AppBundle\Document\File\ProofOfUsageFile;
use AppBundle\Document\File\ProofOfBarringFile;
use AppBundle\Document\File\ProofOfPurchaseFile;
use AppBundle\Document\File\DamagePictureFile;
use AppBundle\Document\File\OtherClaimFile;
use AppBundle\Annotation\DataChange;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ClaimRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Claim
{
    use CurrencyTrait;
    use ImeiTrait;

    const TYPE_LOSS = 'loss';
    const TYPE_THEFT = 'theft';
    const TYPE_DAMAGE = 'damage';
    const TYPE_WARRANTY = 'warranty';
    const TYPE_EXTENDED_WARRANTY = 'extended-warranty';

    const STATUS_FNOL = 'fnol';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_INREVIEW = 'in-review';
    const STATUS_APPROVED = 'approved';
    const STATUS_SETTLED = 'settled';
    const STATUS_DECLINED = 'declined';
    const STATUS_WITHDRAWN = 'withdrawn';
    // Temporary status to allow the system to suggest closing a claim, as the policy is about to be cancelled
    const STATUS_PENDING_CLOSED = 'pending-closed';

    const RISK_GREEN = 'green';
    const RISK_AMBER = 'amber';
    const RISK_RED = 'red';
    const RISK_BLACK = 'black';
    const RISKS = [
        self::RISK_GREEN,
        self::RISK_AMBER,
        self::RISK_RED,
        self::RISK_BLACK
    ];

    const WARNING_FLAG_CLAIMS_NAME_MATCH = 'claim-name-match';
    const WARNING_FLAG_CLAIMS_POSTCODE = 'claim-postcode';
    const WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER = 'claim-replacement-cost-higher';
    const WARNING_FLAG_CLAIMS_INCORRECT_EXCESS = 'claim-incorrect-excess';
    const WARNING_FLAG_CLAIMS_IMEI_MISMATCH = 'claim-imei-mismatch';
    const WARNING_FLAG_CLAIMS_IMEI_UNOBTAINABLE = 'claim-imei-unobtainable';
    const WARNING_FLAG_CLAIMS_HANDLING_TEAM = 'claim-handling-team';
    const WARNING_FLAG_CLAIMS_ALLOW_PICSURE_REDO = 'claim-allow-picsure-redo';

    const WARNING_FLAG_BRIGHTSTAR_NAME_MATCH = 'brightstar-name-match';
    const WARNING_FLAG_BRIGHTSTAR_POSTCODE = 'brighstar-postcode';

    // technically not a warning flag, but fits nicely under that for UI with little change required
    // and very little usage envisioned
    const WARNING_FLAG_IGNORE_USER_DECLINED = 'ignore-user-declined';
    const WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT = 'ignore-policy-expire-claim-wait';

    const NETWORK_ = "";

    const NETWORK_1PMOBILE = "1pMobile";
    const NETWORK_360COMS_TELECOM = "360Coms Telecom";
    const NETWORK_AFRIMOBILE = "AfriMobile";
    const NETWORK_AGE_UK_MY_PHONE = "Age UK My Phone";
    const NETWORK_AIRWAVE_SMART_MOBILE = "Airwave Smart Mobile";
    const NETWORK_ALWAYSONLINE_WIRELESS = "AlwaysOnline Wireless";
    const NETWORK_ANYWHERE_SIM = "Anywhere Sim";
    const NETWORK_ASDA_MOBILE = "Asda Mobile";
    const NETWORK_AURACALL_TRAVEL_TALK = "Auracall Travel Talk";
    const NETWORK_AXIS_TELECOM = "Axis Telecom";
    const NETWORK_BT_MOBILE = "BT Mobile";
    const NETWORK_C4C_MOBILE = "C4C Mobile";
    const NETWORK_CALL_GIVE = "Call & Give";
    const NETWORK_CHAMPIONS_MOBILE = "Champions Mobile";
    const NETWORK_CHATSIM = "ChatSim";
    const NETWORK_CUNIQ = "CUniq";
    const NETWORK_CMLINK = "CMLink";
    const NETWORK_CTEXCEL = "CTExcel";
    const NETWORK_CTRL_MOBILE = "Ctrl Mobile";
    const NETWORK_ECONET_MOBILE = "Econet Mobile";
    const NETWORK_ECONOMY_MOBILE = "Economy Mobile";
    const NETWORK_ECOTALK = "Ecotalk";
    const NETWORK_EE = "EE";
    const NETWORK_ETS_MOBILE = "ETS Mobile";
    const NETWORK_FREEDOMPOP = "FreedomPop";
    const NETWORK_FONOME_MOBILE = "Fonome Mobile";
    const NETWORK_GAMMA_TELECOM = "Gamma Telecom";
    const NETWORK_GIFFGAFF = "giffgaff";
    const NETWORK_GLOBALGIG = "Globalgig";
    const NETWORK_HP_MOBILE_CONNECT = "HP Mobile Connect";
    const NETWORK_ID_MOBILE = "iD Mobile";
    const NETWORK_JOI_TELECOM = "JOi Telecom";
    const NETWORK_JUMP = "Jump";
    const NETWORK_KC_MOBILE = "KC Mobile";
    const NETWORK_LEBARA_MOBILE = "Lebara Mobile";
    const NETWORK_LYCAMOBILE = "Lycamobile";
    const NETWORK_MEEM_MOBILE = "Meem Mobile";
    const NETWORK_NORDTELEKOM = "NordTelekom";
    const NETWORK_NOW_PAYG = "Now PAYG";
    const NETWORK_O2 = "O2";
    const NETWORK_PEBBLE_MOBILE_NETWORK = "Pebble Mobile Network";
    const NETWORK_PIRANHA_MOBILE = "Piranha Mobile";
    const NETWORK_PLUSNET_MOBILE = "Plusnet Mobile";
    const NETWORK_ROK_MOBILE = "Rok Mobile";
    const NETWORK_RWG_MOBILE = "RWG Mobile";
    const NETWORK_SKY_MOBILE = "Sky Mobile";
    const NETWORK_SMARTY = "SMARTY";
    const NETWORK_TALK_HOME_MOBILE = "Talk Home Mobile";
    const NETWORK_TALKMOBILE = "Talkmobile";
    const NETWORK_TALKTALK_MOBILE = "TalkTalk Mobile";
    const NETWORK_TALKXTRA_MOBILE = "TalkXtra Mobile";
    const NETWORK_TELECOM_PLUS = "Telecom Plus";
    const NETWORK_TELFONI = "Telfoni";
    const NETWORK_TESCO_MOBILE = "Tesco Mobile";
    const NETWORK_THE_PEOPLES_OPERATOR = "The People's Operator";
    const NETWORK_THE_PHONE_COOP = "The Phone Co-op";
    const NETWORK_THREE = "Three";
    const NETWORK_TORICA_MOBILE = "Torica Mobile";
    const NETWORK_TOGGLE_MOBILE = "Toggle Mobile";
    const NETWORK_TRUPHONE = "Truphone";
    const NETWORK_U2I_MOBILE = "U2i Mobile";
    const NETWORK_VECTONE_MOBILE = "Vectone Mobile";
    const NETWORK_VIRGIN_MOBILE = "Virgin Mobile";
    const NETWORK_VIVIO = "Vivio";
    const NETWORK_VODAFONE = "Vodafone";
    const NETWORK_VOXI = "VOXI";
    const NETWORK_WHITE_MOBILE = "White Mobile";
    const NETWORK_WORLDSIM = "WorldSim";

    const DAMAGE_BROKEN_SCREEN = "broken-screen";
    const DAMAGE_WATER = "water-damage";
    const DAMAGE_OUT_OF_WARRANTY = "out-of-warranty-breakdown";
    const DAMAGE_OTHER = "other";

    const PHONE_STATUS_NEW = "new";
    const PHONE_STATUS_REFURBISHED = "refurbished";
    const PHONE_STATUS_SECOND_HAND = "second-hand";

    const REPORT_POLICE_STATION = "police-station";
    const REPORT_ONLINE = "online";

    const TEAM_DAVIES = 'davies';
    const TEAM_DIRECT_GROUP = 'direct-group';

    public static $handlingTeams = [
        self::TEAM_DAVIES => self::TEAM_DAVIES,
        self::TEAM_DIRECT_GROUP => self::TEAM_DIRECT_GROUP,
    ];

    public static $handlingTeamEmail = [
        self::TEAM_DAVIES => 'update-claim@so-sure.com',
        self::TEAM_DIRECT_GROUP => 'SoSure@directgroup.co.uk',
    ];

    public static $warningFlags = [
        self::WARNING_FLAG_CLAIMS_NAME_MATCH => self::WARNING_FLAG_CLAIMS_NAME_MATCH,
        self::WARNING_FLAG_CLAIMS_POSTCODE => self::WARNING_FLAG_CLAIMS_POSTCODE,
        self::WARNING_FLAG_BRIGHTSTAR_NAME_MATCH => self::WARNING_FLAG_BRIGHTSTAR_NAME_MATCH,
        self::WARNING_FLAG_BRIGHTSTAR_POSTCODE => self::WARNING_FLAG_BRIGHTSTAR_POSTCODE,
        self::WARNING_FLAG_IGNORE_USER_DECLINED => self::WARNING_FLAG_IGNORE_USER_DECLINED,
        self::WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT => self::WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT,
        self::WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER => self::WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER,
        self::WARNING_FLAG_CLAIMS_INCORRECT_EXCESS => self::WARNING_FLAG_CLAIMS_INCORRECT_EXCESS,
        self::WARNING_FLAG_CLAIMS_IMEI_MISMATCH => self::WARNING_FLAG_CLAIMS_IMEI_MISMATCH,
        self::WARNING_FLAG_CLAIMS_IMEI_UNOBTAINABLE => self::WARNING_FLAG_CLAIMS_IMEI_UNOBTAINABLE,
        self::WARNING_FLAG_CLAIMS_HANDLING_TEAM => self::WARNING_FLAG_CLAIMS_HANDLING_TEAM,
        self::WARNING_FLAG_CLAIMS_ALLOW_PICSURE_REDO => self::WARNING_FLAG_CLAIMS_ALLOW_PICSURE_REDO,
    ];

    public static $claimTypes = [
        self::TYPE_DAMAGE,
        self::TYPE_THEFT,
        self::TYPE_EXTENDED_WARRANTY,
        self::TYPE_WARRANTY,
        self::TYPE_LOSS
    ];

    public static $networks = [
        self::NETWORK_1PMOBILE => self::NETWORK_1PMOBILE,
        self::NETWORK_360COMS_TELECOM => self::NETWORK_360COMS_TELECOM,
        self::NETWORK_AFRIMOBILE => self::NETWORK_AFRIMOBILE,
        self::NETWORK_AGE_UK_MY_PHONE => self::NETWORK_AGE_UK_MY_PHONE,
        self::NETWORK_AIRWAVE_SMART_MOBILE => self::NETWORK_AIRWAVE_SMART_MOBILE,
        self::NETWORK_ALWAYSONLINE_WIRELESS => self::NETWORK_ALWAYSONLINE_WIRELESS,
        self::NETWORK_ANYWHERE_SIM => self::NETWORK_ANYWHERE_SIM,
        self::NETWORK_ASDA_MOBILE => self::NETWORK_ASDA_MOBILE,
        self::NETWORK_AURACALL_TRAVEL_TALK => self::NETWORK_AURACALL_TRAVEL_TALK,
        self::NETWORK_AXIS_TELECOM => self::NETWORK_AXIS_TELECOM,
        self::NETWORK_BT_MOBILE => self::NETWORK_BT_MOBILE,
        self::NETWORK_C4C_MOBILE => self::NETWORK_C4C_MOBILE,
        self::NETWORK_CALL_GIVE => self::NETWORK_CALL_GIVE,
        self::NETWORK_CHAMPIONS_MOBILE => self::NETWORK_CHAMPIONS_MOBILE,
        self::NETWORK_CHATSIM => self::NETWORK_CHATSIM,
        self::NETWORK_CUNIQ => self::NETWORK_CUNIQ,
        self::NETWORK_CMLINK => self::NETWORK_CMLINK,
        self::NETWORK_CTEXCEL => self::NETWORK_CTEXCEL,
        self::NETWORK_CTRL_MOBILE => self::NETWORK_CTRL_MOBILE,
        self::NETWORK_ECONET_MOBILE => self::NETWORK_ECONET_MOBILE,
        self::NETWORK_ECONOMY_MOBILE => self::NETWORK_ECONOMY_MOBILE,
        self::NETWORK_ECOTALK => self::NETWORK_ECOTALK,
        self::NETWORK_EE => self::NETWORK_EE,
        self::NETWORK_ETS_MOBILE => self::NETWORK_ETS_MOBILE,
        self::NETWORK_FREEDOMPOP => self::NETWORK_FREEDOMPOP,
        self::NETWORK_FONOME_MOBILE => self::NETWORK_FONOME_MOBILE,
        self::NETWORK_GAMMA_TELECOM => self::NETWORK_GAMMA_TELECOM,
        self::NETWORK_GIFFGAFF => self::NETWORK_GIFFGAFF,
        self::NETWORK_GLOBALGIG => self::NETWORK_GLOBALGIG,
        self::NETWORK_HP_MOBILE_CONNECT => self::NETWORK_HP_MOBILE_CONNECT,
        self::NETWORK_ID_MOBILE => self::NETWORK_ID_MOBILE,
        self::NETWORK_JOI_TELECOM => self::NETWORK_JOI_TELECOM,
        self::NETWORK_JUMP => self::NETWORK_JUMP,
        self::NETWORK_KC_MOBILE => self::NETWORK_KC_MOBILE,
        self::NETWORK_LEBARA_MOBILE => self::NETWORK_LEBARA_MOBILE,
        self::NETWORK_LYCAMOBILE => self::NETWORK_LYCAMOBILE,
        self::NETWORK_MEEM_MOBILE => self::NETWORK_MEEM_MOBILE,
        self::NETWORK_NORDTELEKOM => self::NETWORK_NORDTELEKOM,
        self::NETWORK_NOW_PAYG => self::NETWORK_NOW_PAYG,
        self::NETWORK_O2 => self::NETWORK_O2,
        self::NETWORK_PEBBLE_MOBILE_NETWORK => self::NETWORK_PEBBLE_MOBILE_NETWORK,
        self::NETWORK_PIRANHA_MOBILE => self::NETWORK_PIRANHA_MOBILE,
        self::NETWORK_PLUSNET_MOBILE => self::NETWORK_PLUSNET_MOBILE,
        self::NETWORK_ROK_MOBILE => self::NETWORK_ROK_MOBILE,
        self::NETWORK_RWG_MOBILE => self::NETWORK_RWG_MOBILE,
        self::NETWORK_SKY_MOBILE => self::NETWORK_SKY_MOBILE,
        self::NETWORK_SMARTY => self::NETWORK_SMARTY,
        self::NETWORK_TALK_HOME_MOBILE => self::NETWORK_TALK_HOME_MOBILE,
        self::NETWORK_TALKMOBILE => self::NETWORK_TALKMOBILE,
        self::NETWORK_TALKTALK_MOBILE => self::NETWORK_TALKTALK_MOBILE,
        self::NETWORK_TALKXTRA_MOBILE => self::NETWORK_TALKXTRA_MOBILE,
        self::NETWORK_TELECOM_PLUS => self::NETWORK_TELECOM_PLUS,
        self::NETWORK_TELFONI => self::NETWORK_TELFONI,
        self::NETWORK_TESCO_MOBILE => self::NETWORK_TESCO_MOBILE,
        self::NETWORK_THE_PEOPLES_OPERATOR => self::NETWORK_THE_PEOPLES_OPERATOR,
        self::NETWORK_THE_PHONE_COOP => self::NETWORK_THE_PHONE_COOP,
        self::NETWORK_THREE => self::NETWORK_THREE,
        self::NETWORK_TORICA_MOBILE => self::NETWORK_TORICA_MOBILE,
        self::NETWORK_TOGGLE_MOBILE => self::NETWORK_TOGGLE_MOBILE,
        self::NETWORK_TRUPHONE => self::NETWORK_TRUPHONE,
        self::NETWORK_U2I_MOBILE => self::NETWORK_U2I_MOBILE,
        self::NETWORK_VECTONE_MOBILE => self::NETWORK_VECTONE_MOBILE,
        self::NETWORK_VIRGIN_MOBILE => self::NETWORK_VIRGIN_MOBILE,
        self::NETWORK_VIVIO => self::NETWORK_VIVIO,
        self::NETWORK_VODAFONE => self::NETWORK_VODAFONE,
        self::NETWORK_VOXI => self::NETWORK_VOXI,
        self::NETWORK_WHITE_MOBILE => self::NETWORK_WHITE_MOBILE,
        self::NETWORK_WORLDSIM => self::NETWORK_WORLDSIM
    ];

    public static $preferedNetworks = [
        self::NETWORK_EE => self::NETWORK_EE,
        self::NETWORK_GIFFGAFF => self::NETWORK_GIFFGAFF,
        self::NETWORK_O2 => self::NETWORK_O2,
        self::NETWORK_TESCO_MOBILE => self::NETWORK_TESCO_MOBILE,
        self::NETWORK_THREE => self::NETWORK_THREE,
        self::NETWORK_VODAFONE => self::NETWORK_VODAFONE
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="claims")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="linkedClaims")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $linkedPolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    public $handler;

    /**
     * @Assert\Choice({"davies", "direct-group"}, strict=true)
     * @MongoDB\Field(type="string")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $handlingTeam;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     * @var Phone
     */
    public $replacementPhone;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $replacementPhoneDetails;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $replacementImei;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $recordedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $submissionDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @DataChange(categories="salva-claim")
     * @var \DateTime
     */
    protected $lossDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @DataChange(categories="salva")
     * @var \DateTime
     */
    protected $notificationDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $replacementReceivedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $createdDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $approvedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $closedDate;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $number;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="5000")
     * @MongoDB\Field(type="string")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $description;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $incidentDate;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $incidentTime;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $location;

    /**
     * @Assert\Choice({"loss", "theft", "damage", "warranty", "extended-warranty"}, strict=true)
     * @MongoDB\Field(type="string")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @Assert\Choice({"fnol", "submitted", "in-review", "approved", "settled", "declined",
     *                 "withdrawn", "pending-closed"}, strict=true)
     * @MongoDB\Field(type="string")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $statusLastUpdated;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $underwriterLastUpdated;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $daviesStatus;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $initialSuspicion;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $finalSuspicion;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $shouldCancelPolicy;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $processed;

    /**
     * @MongoDB\Field(type="float")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $excess;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $unauthorizedCalls;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $accessories;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $phoneReplacementCost;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $transactionFees;

    /**
     * @MongoDB\Field(type="float")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $claimHandlingFees;

    /**
     * @MongoDB\Field(type="float")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $reservedValue;

    /**
     * Cost of claim - excess (does not inc claim handling fee)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $incurred;

    /**
     * Total inc claim handling fee - excess
     * @MongoDB\Field(type="float")
     * @DataChange(categories="salva-claim")
     * @Gedmo\Versioned
     */
    protected $totalIncurred;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $force;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $crimeRef;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $validCrimeRef;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $shippingAddress;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $fnolPicSureValidated;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Charge", mappedBy="claim", cascade={"persist"})
     */
    protected $charges = array();

    /**
     * @MongoDB\Field(type="collection")
     */
    protected $ignoreWarningFlags = array();

    /**
     * @Assert\Choice(callback="getNetworks", strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $network;

    /**
     * @AppAssert\PhoneNumber(message="Please enter a valid phone number")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $phoneToReach;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $timeToReach;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $signature;

    /**
     * @Assert\Choice({"broken-screen", "water-damage", "out-of-warranty-breakdown", "other"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $typeDetails;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="200")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $typeDetailsOther;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="3", max="200")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $monthOfPurchase;

    /**
     * @Assert\Length(min="4", max="4")
     * @Assert\Range(min="2013", max="2050")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $yearOfPurchase;

    /**
     * @Assert\Choice({"new", "refurbished", "second-hand"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $phoneStatus;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $hasContacted;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="200")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $contactedPlace;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $blockedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $reportedDate;

    /**
     * @Assert\Choice({"police-station", "online"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $reportType;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\File\S3ClaimFile",
     *  cascade={"persist"}
     * )
     */
    protected $files = array();

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $supplier;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $supplierStatus;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\Excess")
     * @Gedmo\Versioned
     * @var Excess|null
     */
    protected $expectedExcess;

    public function __construct()
    {
        $this->recordedDate = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function getRecordedDate()
    {
        return $this->recordedDate;
    }

    public function setRecordedDate($recordedDate)
    {
        $this->recordedDate = $recordedDate;
    }

    public function getSubmissionDate()
    {
        return $this->submissionDate;
    }

    public function setSubmissionDate($submissionDate)
    {
        $this->submissionDate = $submissionDate;
    }

    public function isWithin30Days($date)
    {
        $claimsDate = $this->getClosedDate();
        if (!$claimsDate) {
            $claimsDate = $this->getRecordedDate();
        }
        return $claimsDate->diff($date)->days < 30;
    }

    /**
     * @return Policy
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * @return PhonePolicy|null
     */
    public function getPhonePolicy()
    {
        if ($this->policy instanceof PhonePolicy) {
            return $this->policy;
        }

        return null;
    }

    public function setPolicy(Policy $policy)
    {
        if ($policy instanceof PhonePolicy && $this->getFnolPicSureValidated() == null) {
            /** @var PhonePolicy $phonePolicy */
            $phonePolicy = $policy;
            $this->setFnolPicSureValidated($phonePolicy->isPicSureValidated());
        }
        if (!$this->getExpectedExcess() && $policy->getCurrentExcess()) {
            $this->setExpectedExcess($policy->getCurrentExcess());
        }

        $this->policy = $policy;
    }

    /**
     * @return Policy
     */
    public function getLinkedPolicy()
    {
        return $this->linkedPolicy;
    }

    public function setLinkedPolicy($linkedPolicy)
    {
        $this->linkedPolicy = $linkedPolicy;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    public function getHandlingTeam()
    {
        return $this->handlingTeam;
    }

    public function setHandlingTeam($handlingTeam)
    {
        $this->handlingTeam = $handlingTeam;
    }

    public function getHandlingTeamEmail()
    {
        return Claim::$handlingTeamEmail[$this->getHandlingTeam()];
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type, $forceChange = false)
    {
        if ($this->type && $this->type != $type && !$forceChange) {
            throw new \Exception('Unable to change claim type');
        } elseif (!$type) {
            throw new \Exception('Type must be defined');
        }

        $this->type = $type;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        if (!$status) {
            throw new \Exception('Status must be defined');
        }

        if (!in_array($status, [
            self::STATUS_FNOL,
            self::STATUS_SUBMITTED,
            self::STATUS_APPROVED,
            self::STATUS_DECLINED,
            self::STATUS_INREVIEW,
            self::STATUS_PENDING_CLOSED,
            self::STATUS_SETTLED,
            self::STATUS_WITHDRAWN,
        ])) {
            throw new \Exception(sprintf('Status must be a valid status, not %s', $status));
        }

        // Don't trust davies enough with data - changing from approved / settled to declined / withdrawn
        // can have financial and pot reward implications and needs to be checked
        if (in_array($this->status, [self::STATUS_APPROVED, self::STATUS_SETTLED]) &&
            in_array($status, [self::STATUS_DECLINED, self::STATUS_WITHDRAWN])) {
            // @codingStandardsIgnoreStart
            throw new \InvalidArgumentException(sprintf(
                'Unable to change from approved/settled status to declined/withdrawn automatically. Review implication and manually update %s/%s',
                $this->getNumber(),
                $this->getId()
            ));
            // @codingStandardsIgnoreEnd
        }

        if ($this->status != $status) { // status is changing
            $this->setStatusLastUpdated();
        }

        $this->status = $status;
    }

    /**
     * @return \DateTime
     */
    public function getStatusLastUpdated(): \DateTime
    {
        return $this->statusLastUpdated;
    }

    /**
     * @param \DateTime $statusLastUpdated
     */
    public function setStatusLastUpdated(\DateTime $statusLastUpdated = null)
    {
        if ($statusLastUpdated === null) {
            $statusLastUpdated = \DateTime::createFromFormat('U', (string) time());
        }

        $this->statusLastUpdated = $statusLastUpdated;
    }

    /**
     * @return \DateTime
     */
    public function getUnderwriterLastUpdated()
    {
        return $this->underwriterLastUpdated;
    }

    /**
     * @param \DateTime $underwriterLastUpdated
     */
    public function setUnderwriterLastUpdated(\DateTime $underwriterLastUpdated)
    {
        $this->underwriterLastUpdated = $underwriterLastUpdated;
    }

    public function isOpen()
    {
        return in_array($this->getStatus(), [Claim::STATUS_APPROVED, Claim::STATUS_SUBMITTED, Claim::STATUS_INREVIEW]);
    }

    public function isClosed($includeApproved = false)
    {
        $closedStatuses = [
            Claim::STATUS_DECLINED,
            Claim::STATUS_PENDING_CLOSED,
            Claim::STATUS_WITHDRAWN,
            Claim::STATUS_SETTLED,
        ];
        if ($includeApproved) {
            $closedStatuses[] = Claim::STATUS_APPROVED;
        }

        return in_array($this->getStatus(), $closedStatuses);
    }

    public function getDaviesStatus()
    {
        return $this->daviesStatus;
    }

    public function setDaviesStatus($daviesStatus)
    {
        if (!$daviesStatus) {
            throw new \Exception('Status must be defined');
        }

        $this->daviesStatus = $daviesStatus;
    }

    public function getReplacementPhone()
    {
        return $this->replacementPhone;
    }

    public function setReplacementPhone($replacementPhone)
    {
        $this->replacementPhone = $replacementPhone;
    }

    public function getReplacementPhoneDetails()
    {
        return $this->replacementPhoneDetails;
    }

    public function setReplacementPhoneDetails($replacementPhoneDetails)
    {
        $validator = new AlphanumericSpaceDotValidator();

        $this->replacementPhoneDetails = $validator->conform(mb_substr($replacementPhoneDetails, 0, 100));
    }

    public function getReplacementImei()
    {
        return $this->replacementImei;
    }

    public function setReplacementImei($replacementImei)
    {
        $this->replacementImei = $replacementImei;
    }

    public function getReplacementReceivedDate()
    {
        return $this->replacementReceivedDate;
    }

    public function setReplacementReceivedDate($date)
    {
        $this->replacementReceivedDate = $date;
    }

    public function getNumber()
    {
        return $this->number;
    }

    public function setNumber($number, $allowChange = false)
    {
        if ($this->number && $this->number != (string) $number && !$allowChange) {
            throw new \Exception('Unable to change claim number');
        }

        $this->number = (string) $number;
    }

    public function getSuspectedFraud()
    {
        // if finalSuspicion is null return initialSuspicion
        return ($this->finalSuspicion == null) ? $this->initialSuspicion : $this->finalSuspicion;
    }

    public function getInitialSuspicion()
    {
        return $this->initialSuspicion;
    }

    public function setInitialSuspicion($initialSuspicion)
    {
        $this->initialSuspicion = $initialSuspicion;
    }

    public function getFinalSuspicion()
    {
        return $this->finalSuspicion;
    }

    public function setFinalSuspicion($finalSuspicion)
    {
        $this->finalSuspicion = $finalSuspicion;
    }

    public function getShouldCancelPolicy()
    {
        return $this->shouldCancelPolicy;
    }

    public function setShouldCancelPolicy($shouldCancelPolicy)
    {
        $this->shouldCancelPolicy = $shouldCancelPolicy;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        $validator = new AlphanumericSpaceDotValidator();

        $this->notes = $validator->conform(mb_substr($notes, 0, 500));
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getIncidentDate()
    {
        return $this->incidentDate;
    }

    public function setIncidentDate($incidentDate)
    {
        $this->incidentDate = $incidentDate;
    }

    public function getIncidentTime()
    {
        return $this->incidentTime;
    }

    public function setIncidentTime($incidentTime)
    {
        $this->incidentTime = $incidentTime;
    }

    public function getLocation()
    {
        return $this->location;
    }

    public function setLocation($location)
    {
        $this->location = $location;
    }

    /**
     * @return \DateTime
     */
    public function getLossDate()
    {
        return $this->lossDate;
    }

    public function setLossDate($lossDate)
    {
        $this->lossDate = $lossDate;
    }

    /**
     * @return \DateTime
     */
    public function getNotificationDate()
    {
        return $this->notificationDate;
    }

    public function setNotificationDate($notificationDate)
    {
        $this->notificationDate = $notificationDate;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    /**
     * @return \DateTime
     */
    public function getApprovedDate()
    {
        return $this->approvedDate;
    }

    public function setApprovedDate($approvedDate)
    {
        $this->approvedDate = $approvedDate;
    }

    /**
     * @return \DateTime
     */
    public function getClosedDate()
    {
        return $this->closedDate;
    }

    public function setClosedDate($closedDate)
    {
        $this->closedDate = $closedDate;
    }

    public function getExcess()
    {
        return $this->excess;
    }

    public function setExcess($excess)
    {
        $this->excess = $excess;
    }

    public function getClaimHandlingFees()
    {
        return $this->claimHandlingFees;
    }

    public function setClaimHandlingFees($claimHandlingFees)
    {
        $this->claimHandlingFees = $claimHandlingFees;
    }

    public function isWithin30DaysOfPolicyInception()
    {
        if (!$this->getRecordedDate() || !$this->getPolicy() || !$this->getPolicy()->getStart()) {
            return false;
        }

        $diff = $this->getRecordedDate()->diff($this->getPolicy()->getStart());
        return $diff->days < 30;
    }

    public function isPhoneReturnExpected()
    {
        if (in_array($this->getType(), [Claim::TYPE_DAMAGE, Claim::TYPE_WARRANTY, Claim::TYPE_EXTENDED_WARRANTY])) {
            return true;
        } elseif (in_array($this->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            return false;
        } else {
            throw new \Exception(sprintf('Unknown type for phone returned: %s', $this->getType()));
        }
    }

    public function getReservedValue()
    {
        return $this->reservedValue;
    }

    public function setReservedValue($reservedValue)
    {
        $this->reservedValue = $reservedValue;
    }

    public function getIncurred()
    {
        return $this->incurred;
    }

    public function setIncurred($incurred)
    {
        $this->incurred = $incurred;
    }

    public function getTotalIncurred()
    {
        return $this->totalIncurred;
    }

    public function setTotalIncurred($totalIncurred)
    {
        $this->totalIncurred = $totalIncurred;
    }

    public function getUnauthorizedCalls()
    {
        return $this->unauthorizedCalls;
    }

    public function setUnauthorizedCalls($unauthorizedCalls)
    {
        $this->unauthorizedCalls = $unauthorizedCalls;
    }

    public function getAccessories()
    {
        return $this->accessories;
    }

    public function setAccessories($accessories)
    {
        $this->accessories = $accessories;
    }

    public function getPhoneReplacementCost()
    {
        return $this->phoneReplacementCost;
    }

    public function setPhoneReplacementCost($phoneReplacementCost)
    {
        $this->phoneReplacementCost = $phoneReplacementCost;
    }

    public function getTransactionFees()
    {
        return $this->transactionFees;
    }

    public function setTransactionFees($transactionFees)
    {
        $this->transactionFees = $transactionFees;
    }

    public function getProcessed()
    {
        return $this->processed;
    }

    public function setProcessed($processed)
    {
        $this->processed = $processed;
    }

    public function getForce()
    {
        return $this->force;
    }

    public function setForce($force)
    {
        $this->force = $force;
    }

    public function getCrimeRef()
    {
        return $this->crimeRef;
    }

    public function setCrimeRef($crimeRef)
    {
        $this->crimeRef = $crimeRef;
    }

    public function isValidCrimeRef()
    {
        return $this->validCrimeRef;
    }

    public function setValidCrimeRef($validCrimeRef)
    {
        $this->validCrimeRef = $validCrimeRef;
    }

    public function getShippingAddress()
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress($shippingAddress)
    {
        $this->shippingAddress = $shippingAddress;
    }

    public function getFnolRisk()
    {
        return $this->fnolRisk;
    }

    public function setFnolRisk($fnolRisk)
    {
        $this->fnolRisk = $fnolRisk;
    }

    public function getFnolRiskReason()
    {
        return $this->fnolRiskReason;
    }

    public function setFnolRiskReason($fnolRiskReason)
    {
        $this->fnolRiskReason = $fnolRiskReason;
    }

    public function getFnolPicSureValidated()
    {
        return $this->fnolPicSureValidated;
    }

    public function setFnolPicSureValidated($fnolPicSureValidated)
    {
        $this->fnolPicSureValidated = $fnolPicSureValidated;
    }

    public function getCharges()
    {
        return $this->charges;
    }

    public function getLastChargeAmount()
    {
        $charge = $this->getLastCharge();
        if ($charge) {
            return $charge->getAmount();
        }

        return 0;
    }

    public function getLastChargeAmountWithVat()
    {
        $charge = $this->getLastCharge();
        if ($charge) {
            return $charge->getAmountWithVat();
        }

        return 0;
    }

    public function getLastCharge()
    {
        $charges = $this->getCharges();
        if (!is_array($charges)) {
            $charges = $charges->getValues();
        }
        if (count($charges) == 0) {
            return null;
        }

        // sort more recent to older
        usort($charges, function ($a, $b) {
            return $a->getCreatedDate() < $b->getCreatedDate();
        });
        //\Doctrine\Common\Util\Debug::dump($payments, 3);

        return $charges[0];
    }

    public function totalCharges()
    {
        $total = 0;
        foreach ($this->getCharges() as $charge) {
            $total += $charge->getAmount();
        }

        return $total;
    }

    public function totalChargesWithVat()
    {
        $total = 0;
        foreach ($this->getCharges() as $charge) {
            $total += $charge->getAmountWithVat();
        }

        return $total;
    }

    public function addCharge(Charge $charge)
    {
        $charge->setClaim($this);
        $this->charges[] = $charge;
    }

    public function isMonetaryClaim($includeApproved = false)
    {
        $statuses = [self::STATUS_SETTLED];
        if ($includeApproved) {
            $statuses[] = self::STATUS_APPROVED;
        }

        return in_array($this->getStatus(), $statuses);
    }

    public function isLostTheft()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public function isLostTheftApproved()
    {
        // Including inreview to prevent possible multiple claims at the same time
        if ($this->isLostTheft() &&
            in_array($this->getStatus(), [self::STATUS_APPROVED, self::STATUS_SETTLED, self::STATUS_INREVIEW])) {
            return true;
        }

        return false;
    }

    public function isOwnershipTransferClaim()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public function getIgnoreWarningFlags()
    {
        $data = [];
        foreach (static::$warningFlags as $key => $value) {
            $data[$key] = $this->isIgnoreWarningFlagSet($key);
        }

        return $data;
    }

    public function setIgnoreWarningFlags($flag)
    {
        $this->ignoreWarningFlags[] = $flag;
    }

    public function isIgnoreWarningFlagSet($flag)
    {
        return in_array($flag, $this->ignoreWarningFlags);
    }

    public function clearIgnoreWarningFlags()
    {
        $this->ignoreWarningFlags = array();
    }

    public function hasIgnoreUserDeclined()
    {
        return $this->isIgnoreWarningFlagSet(self::WARNING_FLAG_IGNORE_USER_DECLINED);
    }

    public function getNetwork()
    {
        return $this->network;
    }

    public function setNetwork($network)
    {
        $this->network = $network;
    }

    public function getPhoneToReach()
    {
        return $this->phoneToReach;
    }

    public function setPhoneToReach($phoneToReach)
    {
        $this->phoneToReach = $phoneToReach;
    }

    public function getTimeToReach()
    {
        return $this->timeToReach;
    }

    public function setTimeToReach($timeToReach)
    {
        $this->timeToReach = $timeToReach;
    }

    public function getSignature()
    {
        return $this->signature;
    }

    public function setSignature($signature)
    {
        $this->signature = $signature;
    }

    public function getTypeDetails()
    {
        return $this->typeDetails;
    }

    public function setTypeDetails($typeDetails)
    {
        $this->typeDetails = $typeDetails;
    }

    public function getTypeDetailsOther()
    {
        return $this->typeDetailsOther;
    }

    public function setTypeDetailsOther($typeDetailsOther)
    {
        $this->typeDetailsOther = $typeDetailsOther;
    }

    public function getMonthOfPurchase()
    {
        return $this->monthOfPurchase;
    }

    public function setMonthOfPurchase($monthOfPurchase)
    {
        $this->monthOfPurchase = $monthOfPurchase;
    }

    public function getYearOfPurchase()
    {
        return $this->yearOfPurchase;
    }

    public function setYearOfPurchase($yearOfPurchase)
    {
        $this->yearOfPurchase = $yearOfPurchase;
    }

    public function getPhoneStatus()
    {
        return $this->phoneStatus;
    }

    public function setPhoneStatus($phoneStatus)
    {
        $this->phoneStatus = $phoneStatus;
    }

    public function getHasContacted()
    {
        return $this->hasContacted;
    }

    public function setHasContacted($hasContacted)
    {
        $this->hasContacted = $hasContacted;
    }

    public function getContactedPlace()
    {
        return $this->contactedPlace;
    }

    public function setContactedPlace($contactedPlace)
    {
        $this->contactedPlace = $contactedPlace;
    }

    public function getBlockedDate()
    {
        return $this->blockedDate;
    }

    public function setBlockedDate($blockedDate)
    {
        $this->blockedDate = $blockedDate;
    }

    public function getReportedDate()
    {
        return $this->reportedDate;
    }

    public function setReportedDate($reportedDate)
    {
        $this->reportedDate = $reportedDate;
    }

    public function getReportType()
    {
        return $this->reportType;
    }

    public function setReportType($reportType)
    {
        $this->reportType = $reportType;
    }

    public function addFile(S3ClaimFile $file)
    {
        $file->setClaim($this);
        $this->files[] = $file;
    }

    public function getFiles()
    {
        return $this->files;
    }

    public function getSupplier()
    {
        return $this->supplier;
    }

    public function setSupplier($supplier)
    {
        $this->supplier = $supplier;
    }

    public function getSupplierStatus()
    {
        return $this->supplierStatus;
    }

    public function setSupplierStatus($supplierStatus)
    {
        $this->supplierStatus = $supplierStatus;
    }

    public function warnCrimeRef()
    {
        if (mb_strlen($this->getCrimeRef() > 0) && $this->isValidCrimeRef() === false) {
            return true;
        }

        return false;
    }

    public function getExpectedExcess()
    {
        return $this->expectedExcess;
    }

    public function setExpectedExcess(Excess $excess)
    {
        $this->expectedExcess = $excess;
    }

    public function isDuringPolicyPeriod(Policy $policy)
    {
        $periodStart = clone $policy->getStart();
        $periodEnd = clone $policy->getEnd();
        if ($this->getLossDate() && $this->getLossDate() >= $periodStart && $this->getLossDate() < $periodEnd) {
            return true;
        }

        return false;
    }

    /**
     * Gives you the risk surrounding this claim.
     * @param \DateTime|null $date the date for which the risk is being calculated. When left as null it will default
     *                             to the current time and date.
     * @return string containing the name of the risk rating.
     */
    public function getRisk(\DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $picsure = $this->getPolicy()->isPicSureValidated();
        $age = $date->diff($this->getPolicy()->getUser()->getFirstPolicy()->getStart())->m;
        if ($age <= 6) {
            return $picsure ? self::RISK_RED : self::RISK_BLACK;
        } elseif ($age <= 12) {
            return $picsure ? self::RISK_AMBER : self::RISK_BLACK;
        }
        return self::RISK_GREEN;
    }

    /**
     * Gives you the description of the risk surrounding this claim.
     * @param \DateTime|null $date the date for which the risk is being calculated. When left as null it will default
     *                             to the current time and date.
     * @return string containing the description.
     */
    public function getRiskDescription(\DateTime $date = null)
    {
        return static::RISK_DESCRIPTIONS[$this->getRisk($date)];
    }

    public static function sumClaims($claims)
    {
        $data = [
            'total' => 0,
            'approved-settled' => 0,
            self::STATUS_FNOL => 0,
            self::STATUS_SUBMITTED => 0,
            self::STATUS_INREVIEW => 0,
            self::STATUS_APPROVED => 0,
            self::STATUS_SETTLED => 0,
            self::STATUS_DECLINED => 0,
            self::STATUS_WITHDRAWN => 0,
            self::STATUS_PENDING_CLOSED => 0,
        ];
        foreach ($claims as $claim) {
            $data[$claim->getStatus()]++;
            $data['total']++;
            if (in_array($claim->getStatus(), [self::STATUS_APPROVED, self::STATUS_SETTLED])) {
                $data['approved-settled']++;
            }
        }

        return $data;
    }

    public static function attributeClaims($claims, $group = true, $percent = false)
    {
        $total = 0;
        $data = [];
        foreach ($claims as $claim) {
            if ($claim->getPolicy()->getCompany()) {
                $source = 'Company';
            } elseif ($attribution = $claim->getPolicy()->getUser()->getAttribution()) {
                if ($group) {
                    $source = $attribution->getCampaignSourceGroup();
                } else {
                    $source = $attribution->getNormalizedCampaignSource();
                }
            } else {
                $source = Attribution::SOURCE_UNTRACKED;
            }

            if (isset($data[$source])) {
                $data[$source]++;
            } else {
                $data[$source] = 1;
            }
            $total++;
        }

        if ($percent) {
            if ($total == 0) {
                return [];
            }

            $percent = [];
            foreach ($data as $key => $value) {
                $percent[$key] = sprintf("%0.1f%%", (100 * $value / $total));
            }

            return $percent;
        }

        return $data;
    }

    public function toModalArray()
    {
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $this->getPolicy();
        return [
            'number' => $this->getNumber(),
            'notes' => $this->getNotes(),
            'id' => $this->getId(),
            'policyPhone' => $phonePolicy->getPhone()->__toString(),
            'policyId' => $this->getPolicy()->getId(),
            'policyNumber' => $this->getPolicy()->getPolicyNumber(),
            'handler' => $this->getHandler() ? $this->getHandler()->getName() : 'unknown',
            'handlingTeam' => $this->getHandlingTeam(),
            'phoneToReach' => $this->getPhoneToReach(),
            'timeToReach' => $this->getTimeToReach(),
            'typeDetails' => $this->getTypeDetails(),
            'typeDetailsOther' => $this->getTypeDetailsOther(),
            'monthOfPurchase' => $this->getMonthOfPurchase(),
            'yearOfPurchase' => $this->getYearOfPurchase(),
            'phoneStatus' => $this->getPhoneStatus(),
            'hasContacted' => $this->getHasContacted(),
            'contactedPlace' => $this->getContactedPlace(),
            'network' => $this->getNetwork(),
            'blockedDate' => $this->getBlockedDate() ? $this->getBlockedDate()->format(\DateTime::ATOM) : null,
            'reportedDate' => $this->getReportedDate() ? $this->getReportedDate()->format(\DateTime::ATOM) : null,
            'reportType' => $this->getReportType(),
            'replacementPhone' => $this->getReplacementPhone(),
            'replacementPhoneDetails' => $this->getReplacementPhoneDetails(),
            'replacementPhoneId' => $this->getReplacementPhone() ? $this->getReplacementPhone()->getId() : null,
            'replacementImei' => $this->getReplacementImei(),
            'validReplacementImei' => $this->isImei($this->getReplacementImei()),
            'recordedDate' => $this->getRecordedDate() ? $this->getRecordedDate()->format(\DateTime::ATOM) : null,
            'submittedDate' => $this->getSubmissionDate() ? $this->getSubmissionDate()->format(\DateTime::ATOM) : null,
            'approvedDate' => $this->getApprovedDate() ? $this->getApprovedDate()->format(\DateTime::ATOM) : null,
            'lossDate' => $this->getLossDate() ? $this->getLossDate()->format(\DateTime::ATOM) : null,
            'notificationDate' => $this->getNotificationDate() ?
                $this->getNotificationDate()->format(\DateTime::ATOM) :
                null,
            'replacementReceivedDate' => $this->getReplacementReceivedDate() ?
                $this->getReplacementReceivedDate()->format(\DateTime::ATOM) :
                null,
            'closedDate' => $this->getClosedDate() ? $this->getClosedDate()->format(\DateTime::ATOM) : null,
            'description' => $this->getDescription(),
            'incidentDate' => $this->getIncidentDate() ? $this->getIncidentDate()->format(\DateTime::ATOM) : null,
            'incidentTime' => $this->getIncidentTime(),
            'location' => $this->getLocation(),
            'type' => $this->getType(),
            'status' => $this->getStatus(),
            'daviesStatus' => $this->getDaviesStatus(),
            'initialSuspicion' => $this->getInitialSuspicion(),
            'finalSuspicion' => $this->getFinalSuspicion(),
            'shouldCancelPolicy' => $this->getShouldCancelPolicy(),
            'processed' => $this->getProcessed(),
            'excess' => $this->toTwoDp($this->getExcess()),
            'unauthorizedCalls' => $this->toTwoDp($this->getUnauthorizedCalls()),
            'accessories' => $this->toTwoDp($this->getAccessories()),
            'phoneReplacementCost' => $this->toTwoDp($this->getPhoneReplacementCost()),
            'transactionFees' => $this->toTwoDp($this->getTransactionFees()),
            'claimHandlingFees' => $this->toTwoDp($this->getClaimHandlingFees()),
            'reservedValue' => $this->toTwoDp($this->getReservedValue()),
            'totalIncurred' => $this->toTwoDp($this->getTotalIncurred()),
            'force' => $this->getForce(),
            'crimeRef' => $this->getCrimeRef(),
            'validCrimeRef' => $this->isValidCrimeRef(),
            'warnCrimeRef' => $this->warnCrimeRef(),
            'shippingAddress' => $this->getShippingAddress(),
            'needProofOfUsage' => $this->needProofOfUsage(),
            'needProofOfPurchase' => $this->needProofOfPurchase(),
            'needProofOfBarring' => $this->needProofOfBarring(),
            'needProofOfLoss' => $this->needProofOfLoss(),
            'needPictureOfPhone' => $this->needPictureOfPhone(),
        ];
    }

    public function getExpectedExcessValue($type = null, $oldCalculation = false)
    {
        if (in_array($this->getStatus(), [
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN,
        ])) {
            return 0;
        }

        if (!$type) {
            $type = $this->getType();
        }

        if ($oldCalculation) {
            /** @var PhonePolicy $phonePolicy */
            $phonePolicy = $this->getPolicy();
            $picSureEnabled = $phonePolicy->isPicSurePolicy();
            $picSureValidated = $phonePolicy->isPicSureValidatedIncludingClaim($this);

            return self::getExcessValue($type, $picSureValidated, $picSureEnabled);
        }

        if (!$this->getExpectedExcess()) {
            throw new \Exception(sprintf('Missing expected excess for claim %s', $this->getId()));
        }

        return $this->getExpectedExcess()->getValue($type);
    }

    public function getProofOfUsageFiles()
    {
        return $this->getFilesByType(ProofOfUsageFile::class);
    }

    public function getProofOfBarringFiles()
    {
        return $this->getFilesByType(ProofOfBarringFile::class);
    }

    public function getProofOfPurchaseFiles()
    {
        return $this->getFilesByType(ProofOfPurchaseFile::class);
    }

    public function getProofOfLossFiles()
    {
        return $this->getFilesByType(ProofOfLossFile::class);
    }

    public function getDamagePictureFiles()
    {
        return $this->getFilesByType(DamagePictureFile::class);
    }

    public function getOtherFiles()
    {
        return $this->getFilesByType(OtherClaimFile::class);
    }

    public function getAttachmentFiles()
    {
        $files = [];
        foreach ($this->getProofOfUsageFiles() as $file) {
            $files[] = $file;
        }
        foreach ($this->getProofOfBarringFiles() as $file) {
            $files[] = $file;
        }
        foreach ($this->getProofOfPurchaseFiles() as $file) {
            $files[] = $file;
        }
        foreach ($this->getProofOfLossFiles() as $file) {
            $files[] = $file;
        }
        foreach ($this->getDamagePictureFiles() as $file) {
            $files[] = $file;
        }
        foreach ($this->getOtherFiles() as $file) {
            $files[] = $file;
        }

        return $files;
    }

    public function getFilesByType($type)
    {
        $files = [];
        foreach ($this->files as $file) {
            /** @var S3ClaimFile  $file */
            if ($file instanceof $type) {
                $files[] = $file;
            }
        }

        // sort more recent to older
        usort($files, function ($a, $b) {
            return $a->getCreated() < $b->getCreated();
        });

        return $files;
    }

    public function needProofOfUsage()
    {
        return $this->getRisk() !== self::RISK_GREEN;
    }

    public function needProofOfBarring()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public function needProofOfPurchase()
    {
        return $this->getRisk() === self::RISK_BLACK;
    }

    /**
     * Says if the claims handlers need to do veriphy.
     * @return true if so otherwise false.
     */
    public function needVeriphy()
    {
        return in_array($this->getRisk(), [self::RISK_RED, self::RISK_BLACK]);
    }

    public function needPictureOfPhone()
    {
        /** @var PhonePolicy  $policy */
        $policy = $this->getPolicy();
        // TODO: Consider if we should be using claim FNOL pic-sure validation
        // or current policy pic-sure validation
        return $this->getType() == self::TYPE_DAMAGE &&
            in_array($this->getRisk(), [self::RISK_RED, self::RISK_BLACK]) &&
            !$policy->isPicSureValidated();
    }

    public function needProofOfLoss()
    {
        return $this->getType() == self::TYPE_LOSS && $this->getReportType() == self::REPORT_ONLINE;
    }

    public function needValidCrimeRef()
    {
        return $this->getType() == self::TYPE_THEFT ||
            ($this->getType() == self::TYPE_LOSS && $this->getReportType() == self::REPORT_POLICE_STATION);
    }

    public static function getExcessValue($type, $picSureValidated, $picSureEnabled, $repairDiscount = false)
    {
        if ($picSureEnabled && !$picSureValidated) {
            if ($repairDiscount) {
                // £25 discount for repairs offered in some cases by claims team
                return 125;
            }

            return 150;
        }

        if (in_array($type, [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            return 70;
        } elseif (in_array($type, [
            Claim::TYPE_DAMAGE,
            Claim::TYPE_WARRANTY,
            Claim::TYPE_EXTENDED_WARRANTY
        ])) {
            if ($repairDiscount) {
                // £25 discount for repairs offered in some cases by claims team
                return 25;
            }

            return 50;
        }

        throw new \Exception(sprintf('Unknown claim type %s', $type));
    }

    public static function getNetworks()
    {
        return self::$networks;
    }
}
