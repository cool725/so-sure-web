<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * Class Postcode
 * @package AppBundle\Document
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PostcodeRepository")
 */
class Postcode
{
    const OUTCODE = 'outcode';
    const POSTCODE = 'postcode';
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\Length(min="1", max="10")
     * @MongoDB\Field(type="string")
     */
    protected $postcode;

    /**
     * @Assert\Length(min="1", max="10")
     * @MongoDB\Field(type="string")
     */
    protected $postcodeCanonical;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $added;

    /**
     * @MongoDB\Field(type="string")
     */
    protected $type;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(max="1000")
     * @MongoDB\Field(type="string")
     */
    protected $notes;

    public function __construct()
    {
    }

    /**
     * @return mixed
     */
    public function getPostcode()
    {
        return $this->postcode;
    }

    /**
     * @param mixed $postcode
     * @throws \InvalidArgumentException
     * @return Postcode
     */
    public function setPostcode($postcode)
    {
        $this->postcode = $postcode;
        $canonical = $this->canonicalizePostCode($postcode);
        if ($canonical === "invalid") {
            throw new \InvalidArgumentException(
                "The postcode is not valid"
            );
        }
        $this->setPostcodeCanonical($canonical);
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPostcodeCanonical()
    {
        return $this->postcodeCanonical;
    }

    /**
     * @param mixed $postcodeCanonical
     * @return Postcode
     */
    public function setPostcodeCanonical($postcodeCanonical)
    {
        $this->postcodeCanonical = $postcodeCanonical;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAdded()
    {
        return $this->added;
    }

    /**
     * @param mixed $added
     * @return Postcode
     */
    public function setAdded($added)
    {
        $this->added = $added;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param mixed $type
     * @return Postcode
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * @param mixed $notes
     * @return Postcode
     */
    public function setNotes($notes)
    {
        $this->notes = $notes;
        return $this;
    }

    public function canonicalizePostCode($postcode)
    {
        $postcode = mb_strtoupper($postcode);
        $postcode = str_replace(" ", "", $postcode);
        // @codingStandardsIgnoreStart
        $regex = "(([^QVX][0-9]{1,2})|(([^QVX][^IJZ][0-9]{1,2})|(([^QVX][0-9][A-HJKSTUW])|([^QVX][^IJZ][0-9][ABEHMNPRVWXY]))))";
        // @codingStandardsIgnoreEnd
        if (mb_strlen($postcode) >= 6) {
            $this->setType(self::POSTCODE);
            $outCode = mb_substr($postcode, 0, mb_strlen($postcode) -3);
            $inCode = mb_substr($postcode, -3);
            $postcode = $outCode . " " . $inCode;
            $regex = "(" . $regex . "\s?[0-9][^CIKMOV]{2})";
        } else {
            $this->setType(self::OUTCODE);
            $outCode = $postcode;
        }
        $valid = preg_match($regex, $postcode);
        if ($valid) {
            return $postcode;
        } else {
            return "invalid";
        }
    }

    public function getOutCode()
    {
        $split = explode(" ", $this->getPostcodeCanonical());
        return $split[0];
    }
}
