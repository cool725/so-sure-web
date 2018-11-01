<?php

namespace AppBundle\Document;

trait PhoneTrait
{
    public function normalizeUkMobile($mobile, $addZero = false)
    {
        $mobile = str_replace(" ", "", $mobile);
        if ($addZero && preg_match("/^7\d{9,9}/", $mobile)) {
            $mobile = sprintf("+44%s", $mobile);
        } elseif (preg_match("/^07\d{9,9}/", $mobile)) {
            $mobile = sprintf("+44%s", mb_substr($mobile, 1));
        } elseif (preg_match("/^00447\d{9,9}/", $mobile)) {
            $mobile = sprintf("+44%s", mb_substr($mobile, 4));
        }

        return $mobile;
    }

    public function isValidUkMobile($mobile, $addZero = false)
    {
        $mobile = $this->normalizeUkMobile($mobile, $addZero);

        return preg_match("/^\+447\d{9,9}/", $mobile);
    }

    public function normalizeImei($imei)
    {
        // space, -, / may be present when copy/pasted by user
        $imei = str_replace(' ', '', $imei);
        $imei = str_replace('-', '', $imei);
        $imei = str_replace('/', '', $imei);
        // There are some cases of 17 digits imei (15 digit imei with additional info attached)
        // Such as samsung s7 edge
        $imei = mb_substr($imei, 0, 15);

        return $imei;
    }
}
