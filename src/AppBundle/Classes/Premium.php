<?php
namespace AppBundle\Classes;

class Premium
{
    const BROKER_FEE = 0.121;
    const IPT = 0.095;

    private $premium;
    private $discount;
    private $pot;

    /**
     * @param float $premium  Premium / month
     * @param float $discount Monthly discount to be applied (e.g. pot from previous year)
     * @param float $pot      Value of the customer pot / month (e.g £80/12).
     *                        For claims, the pot should be redcued to £10/12 or 0.
     *
     */
    public function __construct($premium, $discount, $pot)
    {
        $this->premium = $premium;
        $this->discount = $discount;
        $this->pot = $pot;
        
        if ($this->pot > $this->premium * 0.8) {
            throw new \Exception('Pot is too big');
        }
    }
    
    public function getPremium()
    {
        return $this->premium;
    }
    
    public function getDiscount()
    {
        return $this->discount;
    }
    
    public function getPot()
    {
        return $this->pot;
    }
    
    public function getUserPayment()
    {
        return $this->premium - $this->discount;
    }
    
    public function getBrokerFee()
    {
        return $this->premium * self::BROKER_FEE / (1 + self::IPT);
    }
    
    public function getGWP()
    {
        return ($this->premium - $this->pot) / (1 + self::IPT);
    }
    
    public function getIPT()
    {
        return $this->getGWP() * self::IPT;
    }
    
    public function getNWT()
    {
        return $this->getGWP() - $this->getBrokerFee();
    }
    
    public function getPayout()
    {
        return $this->pot * 12;
    }
    
    public function getReserveIPT()
    {
        $maxIPT = $this->premium * self::IPT / (1 + self::IPT);

        return $maxIPT - $this->getIPT();
    }
}
