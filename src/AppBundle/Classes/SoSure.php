<?php
namespace AppBundle\Classes;

class SoSure
{
    const SOSURE_TRACKING_COOKIE_NAME = 'sosure-tracking';
    const SOSURE_TRACKING_COOKIE_LENGTH = 31536000; // 365 days

    const SOSURE_EMPLOYEE_COOKIE_NAME = 'sosure-employee';
    const SOSURE_EMPLOYEE_COOKIE_LENGTH = 604800; // 7 days

    const SOSURE_TRACKING_SESSION_NAME = 'sosure-tracking';

    const SOSURE_EMPLOYEE_SALES_EMAIL = 'sales@so-sure.com';

    const POLICY_START = "2016-09-01";
    const TIMEZONE = "Europe/London";

    public static function hasSoSureEmail($email)
    {
        return stripos($email, '@so-sure.com') !== false;
    }

    // make sure uppper case/normalised
    // DE14 added Jan 2017 due to suspecion of fraud in those postcodes based on claims we receieved
    public static $yearlyOnlyPostcodeOutcodes = ['DE14'];

    // make sure uppper case/normalised
    // TN15 7LY add Jan 2017 due to suspecion of fraud in those postcodes based on claims we receieved
    // PE21 7TB added 16/8/17 due to explainable but odd situation from customer triggering manual fraud suspicion
    // OL11 1QA added 21/3/18 due to suspecion of fraud
    public static $yearlyOnlyPostcodes = ['TN15 7LY', 'PE21 7TB', 'OL11 1QA'];
}
