<?php

namespace AppBundle\Document\File;

use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document()
 * @Vich\Uploadable
 */
abstract class UploadFile extends S3File
{
    private $keyFormat;

    /**
     * @MongoDB\Field(type="string")
     *
     * @var string
     */
    private $fileName;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @var \DateTime
     */
    private $updatedAt;

    protected $file;

    /**
     * If manually uploading a file (i.e. not using Symfony Form) ensure an instance
     * of 'UploadedFile' is injected into this setter to trigger the  update. If this
     * bundle's configuration parameter 'inject_on_load' is set to 'true' this setter
     * must be able to accept an instance of 'File' as the bundle will inject one here
     * during Doctrine hydration.
     *
     * @param File|\Symfony\Component\HttpFoundation\File\UploadedFile $file
     *
     */
    public function setFile(File $file = null)
    {
        $this->file = $file;

        if ($file) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updatedAt = new \DateTime('now');
        }
    }

    /**
     * @return File
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * @param string $fileName
     *
     */
    public function setFileName($fileName)
    {
        $this->fileName = $fileName;
        $this->setKey(sprintf($this->keyFormat, $fileName));
    }

    /**
     * @return string
     */
    public function getFileName()
    {
        return $this->fileName;
    }

    public function setKeyFormat($keyFormat)
    {
        $this->keyFormat = $keyFormat;
    }

    /**
     * @return string
     */
    abstract public function getS3FileName();

    public static function combineFiles($files, $method)
    {
        $daily = [];
        foreach ($files as $file) {
            foreach (call_user_func([$file, $method]) as $key => $value) {
                if (!isset($daily[$key]) || $daily[$key] < $value) {
                    $daily[$key] = CurrencyTrait::staticToTwoDp($value);
                }
            }
        }

        return $daily;
    }

    public static function totalCombinedFiles($daily, $year = null, $month = null)
    {
        $total = 0;
        foreach ($daily as $key => $value) {
            $include = true;
            if ($year && $month && mb_stripos($key, sprintf('%d%02d', $year, $month)) === false) {
                $include = false;
            }

            if ($include) {
                $total += (float) $value;
            }
        }

        return $total;
    }
}
