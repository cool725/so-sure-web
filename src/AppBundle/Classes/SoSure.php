<?php
namespace AppBundle\Classes;

class SoSure
{
    const TIMEZONE = "Europe/London";

    public static function hasSoSureEmail($email)
    {
        return stripos($email, '@so-sure.com') !== false;
    }
}
