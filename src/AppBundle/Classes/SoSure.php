<?php
namespace AppBundle\Classes;

class SoSure
{
    const SOSURE_TRACKING_COOKIE_NAME = 'sosure-tracking';
    const SOSURE_TRACKING_COOKIE_LENGTH = 31536000; // 365 days

    const SOSURE_EMPLOYEE_COOKIE_NAME = 'sosure-employee';
    const SOSURE_EMPLOYEE_COOKIE_LENGTH = 604800; // 7 days

    const SOSURE_TRACKING_SESSION_NAME = 'sosure-tracking';

    const TIMEZONE = "Europe/London";

    public static function hasSoSureEmail($email)
    {
        return stripos($email, '@so-sure.com') !== false;
    }

    // make sure uppper case
    //public static $yearlyOnlyPostcodeOutcodes = ['DE14'];
    public static $yearlyOnlyPostcodeOutcodes = [];
}
