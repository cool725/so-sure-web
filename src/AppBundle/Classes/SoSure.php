<?php
namespace AppBundle\Classes;

class SoSure
{
    const SOSURE_TRACKING_COOKIE_NAME = 'sosure-tracking';
    const SOSURE_TRACKING_COOKIE_LENGTH = 31536000; // 365 days

    const SOSURE_EMPLOYEE_COOKIE_NAME = 'sosure-employee';
    const SOSURE_EMPLOYEE_COOKIE_LENGTH = 604800; // 7 days

    const SOSURE_TRACKING_SESSION_NAME = 'sosure-tracking';

    const POLICY_START = "2016-09-01";
    const TIMEZONE = "Europe/London";

    public static function hasSoSureEmail($email)
    {
        return stripos($email, '@so-sure.com') !== false;
    }

    // make sure uppper case/normalised
    // Dylan requested DE14 & TN15 7LY in Jan 2017 due to suspecion of fraud in those
    // postcodes based on claims we receieved
    public static $yearlyOnlyPostcodeOutcodes = ['DE14'];
    public static $yearlyOnlyPostcodes = ['TN15 7LY'];
}
