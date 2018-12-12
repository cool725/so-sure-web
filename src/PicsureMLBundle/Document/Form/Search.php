<?php

namespace PicsureMLBundle\Document\Form;

class Search
{
    /** @var int */
    protected $version;
    
    /** @var string */
    protected $label;

    /** @var bool */
    protected $forDetection;

    /** @var int */
    protected $imagesPerPage;

    public function getVersion()
    {
        return $this->version;
    }
    
    public function setVersion(int $version)
    {
        $this->version = $version;
    }
    
    public function getLabel()
    {
        return $this->label;
    }
    
    public function setLabel(String $label)
    {
        $this->label = $label;
    }

    public function getForDetection()
    {
        return $this->forDetection;
    }
    
    public function setForDetection(bool $forDetection)
    {
        $this->forDetection = $forDetection;
    }

    public function getImagesPerPage()
    {
        return $this->imagesPerPage;
    }

    public function setImagesPerPage(int $imagesPerPage)
    {
        $this->imagesPerPage = $imagesPerPage;
    }
}
