<?php

namespace PicsureMLBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="PicsureMLBundle\Repository\PicsureMLRepository")
 */
class Image
{
	
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\Field(type="string", name="path")
     */
    protected $path;

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

    public function getId()
    {
        return $this->id;
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function getPath()
    {
        return $this->path;
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

    public function setAnnotation($x, $y, $width, $height)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
    }

    public function getAnnotation()
    {
        return '['.$this->x.','.$this->y.','.$this->width.','.$this->height.']';
    }

}
