<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document()
 * @Gedmo\Loggable
 */
class SCode
{
    const TYPE_STANDARD = 'standard';
    const TYPE_PAYGROUP = 'paygroup';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true)
     * @Gedmo\Versioned
     */
    protected $code;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $policy;

    /** @MongoDB\Field(type="boolean") */
    protected $active;

    /**
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

    public function generateRandomCode()
    {
        $this->setCode(substr(base64_encode(uniqid(mt_rand(), true)), 0, 8));
    }

    public static function isValidSCode($scode)
    {
        return preg_match("/^[a-zA-Z0-9\/+]{8,8}/", $scode) === 1;
    }

    public function deactivate()
    {
        $this->setCode(base64_encode(uniqid(mt_rand(), true)));
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
        if (!in_array($type, [self::TYPE_STANDARD, self::TYPE_PAYGROUP])) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid type'));
        }

        $this->type = $type;
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

    public function isStandard()
    {
        return $this->getType() == self::TYPE_STANDARD;
    }

    public function toApiArray()
    {
        return [
            'code' => $this->getCode(),
            'share_link' => $this->getShareLink(),
            'sharer_name' => $this->getPolicy()->getUser()->getName(),
            'type' => $this->getType(),
            'active' => $this->isActive() ? true : false,
        ];
    }
}
