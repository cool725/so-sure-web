<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\Payment\ChargebackPayment;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Chargebacks
{
    /**
     * @var Policy
     */
    protected $policy;

    /**
     * @var ChargebackPayment
     */
    protected $chargeback;


    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getChargeback()
    {
        return $this->chargeback;
    }

    public function setChargeback($chargeback)
    {
        $this->chargeback = $chargeback;
    }
}
