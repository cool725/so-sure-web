<?php

namespace PicsureMLBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\Document(repositoryClass="PicsureMLBundle\Repository\TrainingVersionsInfoRepository")
 */
class TrainingVersionsInfo
{

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\Field(type="integer", name="lastestVersion")
     */
    protected $lastestVersion;

    /**
     * @MongoDB\Field(type="collection", name="versions")
     */
    protected $versions = array();

    public function getId()
    {
        return $this->id;
    }

    public function setLatestVersion($lastestVersion)
    {
        $this->lastestVersion = $lastestVersion;
    }

    public function getLatestVersion()
    {
        return $this->lastestVersion;
    }

    public function addVersion($version)
    {
        $this->versions[] = $version;
    }

    public function getVersions()
    {
        return $this->versions;
    }

}
