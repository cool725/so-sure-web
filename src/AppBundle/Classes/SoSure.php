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

    const S3_BUCKET_ADMIN = 'admin.so-sure.com';
    const S3_BUCKET_POLICY = 'policy.so-sure.com';

    public static function getSoSureTimezone()
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    public static function hasSoSureEmail($email)
    {
        return mb_stripos($email, '@so-sure.com') !== false;
    }

    public static function hasSoSureRewardsEmail($email)
    {
        return mb_stripos($email, '@so-sure.net') !== false;
    }

    // make sure uppper case/normalised
    // DE14 added Jan 2017 due to suspecion of fraud in those postcodes based on claims we receieved
    public static $yearlyOnlyPostcodeOutcodes = ['DE14'];

    // make sure uppper case/normalised
    // TN15 7LY add Jan 2017 due to suspecion of fraud in those postcodes based on claims we receieved
    // PE21 7TB added 16/8/17 due to explainable but odd situation from customer triggering manual fraud suspicion
    // OL11 1QA added 21/3/18 due to suspecion of fraud
    // WN1 2XD added 23/4/18 due to suspected fraud for Mob/2018/5503304
    // TW15 1LN added 1/5/18 due to attempting to insure an already damaged phone
    // CB6 1DD added 23/8/18 reason unknown
    public static $yearlyOnlyPostcodes = ['TN15 7LY', 'PE21 7TB', 'OL11 1QA', 'WN1 2XD', 'TW15 1LN', 'CB6 1DD'];

    public static function getActivationInterval()
    {
        return new \DateInterval('P15D');
    }

    public static function getHardActivationInterval()
    {
        return new \DateInterval('P90D');
    }

    public static function encodeCommunicationsHash($email)
    {
        return urlencode(base64_encode($email));
    }

    public static function decodeCommunicationsHash($hash)
    {
        return base64_decode(urldecode($hash));
    }
}
