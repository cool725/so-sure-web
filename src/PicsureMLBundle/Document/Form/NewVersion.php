<?php

namespace PicsureMLBundle\Document\Form;

class NewVersion
{
    /** @var int */
    protected $version;
    
    public function getVersion()
    {
        return $this->version;
    }
    
    public function setVersion(int $version)
    {
        $this->version = $version;
    }
}
