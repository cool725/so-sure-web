<?php

namespace PicsureMLBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\Document(repositoryClass="PicsureMLBundle\Repository\TrainingDataRepository")
 */
class TrainingData
{
    const LABEL_UNDAMAGED = 'undamaged';
    const LABEL_INVALID = 'invalid';
    const LABEL_DAMAGED = 'damaged';
    const LABEL_UNKNOWN = 'unknown';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\Field(type="string", name="bucket")
     */
    protected $bucket;

    /**
     * @MongoDB\Field(type="string", name="imagePath")
     */
    protected $imagePath;

    /**
     * @Assert\Choice({"undamaged", "invalid", "damaged"}, strict=true)
     * @MongoDB\Field(type="string", name="label")
     */
    protected $label;

    /**
     * @MongoDB\Field(type="integer", name="x")
     */
    protected $x;

    /**
     * @MongoDB\Field(type="integer", name="y")
     */
    protected $y;

    /**
     * @MongoDB\Field(type="integer", name="width")
     */
    protected $width;

    /**
     * @MongoDB\Field(type="integer", name="height")
     */
    protected $height;

    /**
     * @MongoDB\Field(type="collection", name="versions")
     */
    protected $versions = array();

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $forDetection;

    public function getId()
    {
        return $this->id;
    }

    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
    }

    public function getBucket()
    {
        return $this->bucket;
    }

    public function setImagePath($imagePath)
    {
        $this->imagePath = $imagePath;
    }

    public function getImagePath()
    {
        return $this->imagePath;
    }

    public function setLabel($label)
    {
        $this->label = $label;
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function hasLabel()
    {
        return $this->label != null;
    }

    public function setX($x)
    {
        $this->x = $x;
    }

    public function getX()
    {
        return $this->x;
    }

    public function setY($y)
    {
        $this->y = $y;
    }

    public function getY()
    {
        return $this->y;
    }

    public function setWidth($width)
    {
        $this->width = $width;
    }

    public function getWidth()
    {
        return $this->width;
    }

    public function setHeight($height)
    {
        $this->height = $height;
    }

    public function getHeight()
    {
        return $this->height;
    }
    
    public function addVersion($version)
    {
        $this->versions[] = $version;
    }

    public function setVersions($versions)
    {
        $this->versions = $versions;
    }

    public function getVersions()
    {
        return $this->versions;
    }

    public function setForDetection($forDetection)
    {
        $this->forDetection = $forDetection;
    }

    public function getForDetection()
    {
        return $this->forDetection;
    }

    public function hasAnnotation()
    {
        return $this->x != null && $this->y != null && $this->width != null && $this->height != null;
    }
}
