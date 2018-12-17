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

    const AUTO_CANCEL_IN_COOLOFF = 'auto-cancel-in-cooloff';

    // Moved to MIXPANEL_CPC_QUOTES_UK mid June 2017
    const MIXPANEL_LANDING_UK = 'mixpanel-landing-uk';
    const MIXPANEL_CPC_QUOTES_UK = 'mixpanel-cpc-quotes-uk';

    const MIXPANEL_QUOTES_UK = 'mixpanel-quotes-uk';
    const MIXPANEL_CPC_MANUFACTURER_UK = 'mixpanel-cpc-manufacturer-uk';
    const MIXPANEL_CPC_COMPETITORS_UK = 'mixpanel-cpc-competitors-uk';
    const MIXPANEL_CLICK_BUY_NOW = 'mixpanel-click-buy-now';
    const MIXPANEL_RECEIVE_PERSONAL_DETAILS = 'mixpanel-receive-personal-details';
    const MIXPANEL_POLICY_READY = 'mixpanel-policy-ready';
    const MIXPANEL_PURCHASE_POLICY = 'mixpanel-purchase-policy';
    const MIXPANEL_INVITE_SOMEONE = 'mixpanel-invite-someone';
    const MIXPANEL_VIEW_INVITATION_SCODE = 'mixpanel-view-invitation-scode';
    const MIXPANEL_VIEW_INVITATION_EMAIL = 'mixpanel-view-invitation-email';
    const MIXPANEL_VIEW_INVITATION_SMS = 'mixpanel-view-invitation-sms';
    const MIXPANEL_PURCHASE_POLICY_APP_ATTRIB = 'mixpanel-purchase-policy-app-attrib';
    const MIXPANEL_GOOGLE = 'mixpanel-purchase-policy-app-attrib';

    const KPI_PICSURE_TOTAL_UNSTARTED_POLICIES = 'kpi-picsure-unstarted-policies';
    const KPI_PICSURE_TOTAL_APPROVED_POLICIES = 'kpi-picsure-approved-policies';
    const KPI_PICSURE_TOTAL_REJECTED_POLICIES = 'kpi-picsure-rejected-policies';
    const KPI_PICSURE_TOTAL_PREAPPROVED_POLICIES = 'kpi-picsure-preapproved-policies';
    const KPI_PICSURE_TOTAL_CLAIMS_APPROVED_POLICIES = 'kpi-picsure-claims-approved-policies';
    const KPI_PICSURE_TOTAL_INVALID_POLICIES = 'kpi-picsure-invalid-policies';

    const KPI_PICSURE_ACTIVE_UNSTARTED_POLICIES = 'kpi-picsure-active-unstarted-policies';
    const KPI_PICSURE_ACTIVE_APPROVED_POLICIES = 'kpi-picsure-active-approved-policies';
    const KPI_PICSURE_ACTIVE_REJECTED_POLICIES = 'kpi-picsure-active-rejected-policies';
    const KPI_PICSURE_ACTIVE_PREAPPROVED_POLICIES = 'kpi-picsure-active-preapproved-policies';
    const KPI_PICSURE_ACTIVE_CLAIMS_APPROVED_POLICIES = 'kpi-picsure-active-claims-approved-policies';
    const KPI_PICSURE_ACTIVE_INVALID_POLICIES = 'kpi-picsure-active-invalid-policies';

    const KPI_CANCELLED_AND_PAYMENT_OWED = 'kpi-cancelled-payment-owed';
    const KPI_CANCELLED_AND_PAYMENT_PAID = 'kpi-cancelled-payment-paid';

    const ACCOUNTS_AVG_PAYMENTS = 'accounts-avg-payments';
    const ACCOUNTS_ACTIVE_POLICIES = 'accounts-active-policies';
    const ACCOUNTS_ACTIVE_POLICIES_WITH_DISCOUNTS = 'accounts-active-policies-with-discounts';
    const ACCOUNTS_REWARD_POT_LIABILITY_SALVA = 'accounts-reward-pot-liability-salva';
    const ACCOUNTS_REWARD_POT_LIABILITY_SOSURE = 'accounts-reward-pot-liability-sosure';
    const ACCOUNTS_ANNUAL_RUN_RATE = 'accounts-annual-run-rate';

    public static $allStats = [
        Stats::INSTALL_GOOGLE,
        Stats::INSTALL_APPLE,
        Stats::MIXPANEL_TOTAL_SITE_VISITORS,
        Stats::MIXPANEL_QUOTES_UK,
        Stats::MIXPANEL_RECEIVE_PERSONAL_DETAILS,
        Stats::MIXPANEL_CPC_QUOTES_UK,
        Stats::MIXPANEL_CPC_MANUFACTURER_UK,
        Stats::MIXPANEL_CPC_COMPETITORS_UK,
        Stats::KPI_PICSURE_TOTAL_UNSTARTED_POLICIES,
        Stats::KPI_PICSURE_TOTAL_APPROVED_POLICIES,
        Stats::KPI_PICSURE_TOTAL_PREAPPROVED_POLICIES,
        Stats::KPI_PICSURE_TOTAL_REJECTED_POLICIES,
        Stats::KPI_CANCELLED_AND_PAYMENT_OWED,
        Stats::KPI_CANCELLED_AND_PAYMENT_PAID,
    ];

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @MongoDB\Index(unique=false, sparse=true)
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
        $this->date = \DateTime::createFromFormat('U', time());
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
            self::KPI_PICSURE_TOTAL_UNSTARTED_POLICIES,
            self::KPI_PICSURE_TOTAL_APPROVED_POLICIES,
            self::KPI_PICSURE_TOTAL_PREAPPROVED_POLICIES,
            self::KPI_PICSURE_TOTAL_REJECTED_POLICIES,
        ])) {
            return true;
        }

        return false;
    }

    public static function sum($stats, $dashIfMissing = true)
    {
        $data = [];
        foreach ($stats as $stat) {
            /** @var Stats $stat */
            if (!isset($data[$stat->getName()])) {
                $data[$stat->getName()] = 0;
            }
            if (!$stat->isAbsolute()) {
                $data[$stat->getName()] += $stat->getValue();
            } else {
                $data[$stat->getName()] = $stat->getValue();
            }
        }

        if ($dashIfMissing) {
            foreach (Stats::$allStats as $stat) {
                if (!isset($data[$stat])) {
                    $data[$stat] = '-';
                }
            }
        }

        return $data;
    }
}
