<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\CurrencyTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\File\LloydsFileRepository")
 * @Vich\Uploadable
 */
class LloydsFile extends UploadFile
{
    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     *
     * @Vich\UploadableField(mapping="adminS3Mapping", fileNameProperty="fileName")
     *
     * @var File
     */
    protected $file;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyReceived = array();

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $dailyProcessing = array();
    
    /**
     * @return string
     */
    public function getS3FileName()
    {
        $now = new \DateTime();

        return sprintf(
            'banking/lloyds-%d-%02d-%s',
            $this->getDate()->format('Y'),
            $this->getDate()->format('m'),
            $now->format('U')
        );
    }

    public function getDailyReceived()
    {
        return $this->dailyReceived;
    }

    public function setDailyReceived($dailyReceived)
    {
        $this->dailyReceived = $dailyReceived;
    }

    public function getDailyProcessing()
    {
        return $this->dailyProcessing;
    }

    public function setDailyProcessing($dailyProcessing)
    {
        $this->dailyProcessing = $dailyProcessing;
    }

    public static function combineDailyReceived($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyReceived');
    }

    public static function combineDailyProcessing($lloydsFiles)
    {
        return self::combineFiles($lloydsFiles, 'getDailyProcessing');
    }
}
