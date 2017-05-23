<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\SCodeRepository")
 * @Gedmo\Loggable
 * @MongoDB\Index(keys={"code"="asc", "reward.id"="asc", "policy.id"="asc"}, sparse="true", unique="true")
 */
class SCode
{
    const TYPE_STANDARD = 'standard';
    const TYPE_MULTIPAY = 'multipay';
    const TYPE_AFFILIATE = 'affiliate';
    const TYPE_REWARD = 'reward';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\Length(min="2", max="50")
     * @AppAssert\Alphanumeric()
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $code;

    /**
     * @Assert\Choice({"standard", "multipay", "affiliate", "reward"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Reward")
     * @Gedmo\Versioned
     */
    protected $reward;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $active;

    /**
     * @Assert\Url(protocols = {"http", "https"})
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $shareLink;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\User")
     */
    protected $acceptors = array();

    public function __construct()
    {
        $this->createdDate = new \DateTime();
        $this->active = true;
        $this->setType(self::TYPE_STANDARD);
        $this->generateRandomCode();
    }

    public static function getNameForCode(User $user, $type)
    {
        if (!$type) {
            $type == self::TYPE_STANDARD;
        }

        $prefix = self::getPrefix($type);

        if ($type == self::TYPE_STANDARD) {
            $length = 4;
        } elseif ($type == self::TYPE_MULTIPAY) {
            $length = 2;
        } else {
            throw new \Exception(sprintf('Unknown type %s', $type));
        }

        $firstName = str_pad($user->getFirstName(), 1, "0");
        $lastName = str_pad($user->getLastName(), $length, "0");
        $name = sprintf("%s%s%s", $prefix, substr($firstName, 0, 1), substr($lastName, 0, $length - 1));

        return trim($name);
    }

    public static function getPrefix($type)
    {
        if ($type == self::TYPE_STANDARD) {
            return null;
        } elseif ($type == self::TYPE_MULTIPAY) {
            return 'P-';
        } else {
            throw new \Exception(sprintf('Unknown type %s', $type));
        }
    }

    public function generateNamedCode(User $user, $count)
    {
        // getName should be 4 to 6 chars
        $name = self::getNameForCode($user, $this->getType());
        $code = sprintf("%s%s", $name, str_pad($count, 8 - strlen($name), "0", STR_PAD_LEFT));

        if (strlen($code) > 8) {
            $code = substr($code, 0, 8);
        }
        if (strlen($code) != 8) {
            throw new \Exception(sprintf('SCode %s is not 8 character', $code));
        }

        $this->setCode(strtolower($code));
    }

    public function generateRandomCode()
    {
        $randBase64 = $this->removeDisallowedBase64Chars(base64_encode(random_bytes(12)));
        $this->setCode(strtolower(substr($randBase64, 0, 8)));
    }

    public function removeDisallowedBase64Chars($string)
    {
        $string = str_replace('/', '', $string);
        $string = str_replace('=', '', $string);
        $string = str_replace('+', '', $string);

        return $string;
    }

    public static function isValidSCode($scode)
    {
        // TODO: Consider adding additional scodes to users and gradually phase out old ones
        // should be able one day to remove the uppercase a-z validation here
        return preg_match("/^[-a-zA-Z0-9\/+]{8,8}$/", $scode) === 1;
    }

    public function deactivate()
    {
        // see policy service::uniqueSCode for reason behind creating a new code
        $this->generateRandomCode();
        $this->setActive(false);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    public function getCode()
    {
        return $this->code;
    }

    public function setCode($code)
    {
        $this->code = $code;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        if (!in_array($type, [self::TYPE_STANDARD, self::TYPE_MULTIPAY, self::TYPE_REWARD])) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid type'));
        }

        $this->type = $type;
    }

    public function getReward()
    {
        return $this->reward;
    }

    public function setReward(Reward $reward)
    {
        $reward->setSCode($this);
        $this->reward = $reward;
    }

    public function isActive()
    {
        return $this->active;
    }

    public function setActive($active)
    {
        $this->active = $active;
    }

    public function getShareLink()
    {
        return $this->shareLink;
    }

    public function setShareLink($shareLink)
    {
        $this->shareLink = $shareLink;
    }

    public function getAcceptors()
    {
        return $this->acceptors;
    }

    public function addAcceptor(User $acceptor)
    {
        $acceptor->setAcceptedSCode($this);
        $this->acceptors[] = $acceptor;
    }

    public function getUser()
    {
        if ($this->getPolicy()) {
            return $this->getPolicy()->getUser();
        } elseif ($this->getReward()) {
            return $this->getReward()->getUser();
        }

        return null;
    }
    
    public function isStandard()
    {
        return $this->getType() == self::TYPE_STANDARD;
    }

    public function isReward()
    {
        return $this->getType() == self::TYPE_REWARD;
    }

    public function toApiArray()
    {
        return [
            'code' => $this->getCode(),
            'share_link' => $this->getShareLink(),
            'sharer_name' => $this->getUser()->getName(),
            'type' => $this->getType(),
            'active' => $this->isActive() ? true : false,
        ];
    }

    public function __clone()
    {
        $scode = new SCode();
        $scode->setActive($this->isActive());
        $scode->setCode($this->getCode());
        $scode->setShareLink($this->getShareLink());
        $scode->setType($this->getType());

        return $scode;
    }
}
