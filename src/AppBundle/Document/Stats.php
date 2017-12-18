<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\StatsRepository")
 */
class Stats
{
    const INSTALL_APPLE = 'install-apple';
    const INSTALL_GOOGLE = 'install-google';
    const MIXPANEL_TOTAL_SITE_VISITORS = 'mixpanel-total-site-visitors';

    // Moved to MIXPANEL_CPC_QUOTES_UK mid June 2017
    const MIXPANEL_LANDING_UK = 'mixpanel-landing-uk';
    const MIXPANEL_CPC_QUOTES_UK = 'mixpanel-cpc-quotes-uk';

    const MIXPANEL_QUOTES_UK = 'mixpanel-quotes-uk';
    const MIXPANEL_CPC_MANUFACTURER_UK = 'mixpanel-cpc-manufacturer-uk';
    const MIXPANEL_CPC_COMPETITORS_UK = 'mixpanel-cpc-competitors-uk';
    const MIXPANEL_CLICK_BUY_NOW = 'mixpanel-click-buy-now';
    const MIXPANEL_RECEIVE_PERSONAL_DETAILS = 'mixpanel-receive-personal-details';
    const MIXPANEL_INVITE_SOMEONE = 'mixpanel-invite-someone';
    const MIXPANEL_VIEW_INVITATION_SCODE = 'mixpanel-view-invitation-scode';
    const MIXPANEL_VIEW_INVITATION_EMAIL = 'mixpanel-view-invitation-email';
    const MIXPANEL_VIEW_INVITATION_SMS = 'mixpanel-view-invitation-sms';
    const MIXPANEL_PURCHASE_POLICY_APP_ATTRIB = 'mixpanel-purchase-policy-app-attrib';

    const KPI_PICSURE_UNSTARTED_POLICIES = 'kpi-picsure-unstarted-policies';
    const KPI_PICSURE_APPROVED_POLICIES = 'kpi-picsure-approved-policies';
    const KPI_PICSURE_REJECTED_POLICIES = 'kpi-picsure-rejected-policies';
    const KPI_PICSURE_PREAPPROVED_POLICIES = 'kpi-picsure-preapproved-policies';

    const KPI_CANCELLED_AND_PAYMENT_OWED = 'kpi-cancelled-payment-owed';
    const KPI_CANCELLED_AND_PAYMENT_PAID = 'kpi-cancelled-payment-paid';

    public static $allStats = [
        Stats::INSTALL_GOOGLE,
        Stats::INSTALL_APPLE,
        Stats::MIXPANEL_TOTAL_SITE_VISITORS,
        Stats::MIXPANEL_QUOTES_UK,
        Stats::MIXPANEL_RECEIVE_PERSONAL_DETAILS,
        Stats::MIXPANEL_CPC_QUOTES_UK,
        Stats::MIXPANEL_CPC_MANUFACTURER_UK,
        Stats::MIXPANEL_CPC_COMPETITORS_UK,
        Stats::KPI_PICSURE_UNSTARTED_POLICIES,
        Stats::KPI_PICSURE_APPROVED_POLICIES,
        Stats::KPI_PICSURE_PREAPPROVED_POLICIES,
        Stats::KPI_PICSURE_REJECTED_POLICIES,
        Stats::KPI_CANCELLED_AND_PAYMENT_OWED,
        Stats::KPI_CANCELLED_AND_PAYMENT_PAID,
    ];

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $date;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    /**
     * @MongoDB\Field(type="float", nullable=false)
     */
    protected $value;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }
    
    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    public function isAbsolute()
    {
        if (in_array($this->getName(), [
            self::KPI_CANCELLED_AND_PAYMENT_OWED,
            self::KPI_PICSURE_UNSTARTED_POLICIES,
            self::KPI_PICSURE_APPROVED_POLICIES,
            self::KPI_PICSURE_PREAPPROVED_POLICIES,
            self::KPI_PICSURE_REJECTED_POLICIES,
        ])) {
            return true;
        }

        return false;
    }
}
