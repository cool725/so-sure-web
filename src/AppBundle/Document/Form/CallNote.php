<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class CallNote extends \AppBundle\Document\Note\CallNote
{
    protected $policyId;

    public function getPolicyId()
    {
        return $this->policyId;
    }

    public function setPolicyId($policyId)
    {
        $this->policyId = $policyId;
    }

    public function toCallNote()
    {
        $callNote = new \AppBundle\Document\Note\CallNote();
        $callNote->setUser($this->getUser());
        $callNote->setResult($this->getResult());
        $callNote->setNotes($this->getNotes());
        $callNote->setEmailed($this->getEmailed());
        $callNote->setSms($this->getSms());
        $callNote->setVoicemail($this->getVoicemail());

        return $callNote;
    }
}
