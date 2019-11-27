<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\File\PicSureFile;
use Symfony\Component\Validator\Constraints as Assert;

class PicSureStatus
{
    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    protected $note;

    protected $picSureStatus;

    public function getPicSureStatus()
    {
        return $this->picSureStatus;
    }

    public function setPicSureStatus($picSureStatus)
    {
        if ($picSureStatus === "") {
            // remove pic-sure files
            foreach ($this->policy->getPolicyFilesByType(PicSureFile::class) as $file) {
                $this->policy->removePolicyFile($file);
            }
        }
        $this->picSureStatus = $picSureStatus;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $policy;
        $this->policy = $phonePolicy;
        $this->picSureStatus = $phonePolicy->getPicSureStatus();
    }

    public function getNote()
    {
        return $this->note;
    }

    public function setNote($note)
    {
        $this->note = $note;
    }
}
