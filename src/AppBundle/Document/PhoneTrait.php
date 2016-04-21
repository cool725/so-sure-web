<?php

namespace AppBundle\Document;

trait PhoneTrait
{
    public function normalizeUkMobile($mobile)
    {
        $mobile = str_replace(" ", "", $mobile);
        if (preg_match("/^07\d{9,9}/", $mobile)) {
            $mobile = sprintf("+44%s", substr($mobile, 1));
        } elseif (preg_match("/^00447\d{9,9}/", $mobile)) {
            $mobile = sprintf("+44%s", substr($mobile, 4));
        }
        
        return $mobile;
    }
}
