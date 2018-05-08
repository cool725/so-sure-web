<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\CurrencyTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\File\BarclaysStatementFileRepository")
 * @Vich\Uploadable
 */
class BarclaysStatementFile extends UploadFile
{
    use CurrencyTrait;

    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     *
     * @Vich\UploadableField(mapping="adminS3Mapping", fileNameProperty="fileName")
     *
     * @var File
     */
    protected $file;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $monthlyTotal;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $chargebacks = array();
    
   /**
     * @return string
     */
    public function getS3FileName()
    {
        $now = new \DateTime();

        return sprintf(
            'banking/barclays-statement-%d-%02d-%s',
            $this->getDate()->format('Y'),
            $this->getDate()->format('m'),
            $now->format('U')
        );
    }

    public function getMonthlyTotal()
    {
        return $this->monthlyTotal;
    }

    public function setMonthlyTotal($monthlyTotal)
    {
        $this->monthlyTotal = $monthlyTotal;
    }

    public function getChargebacks()
    {
        return $this->chargebacks;
    }

    public function setChargebacks($chargebacks)
    {
        $this->chargebacks = $chargebacks;
    }
}
