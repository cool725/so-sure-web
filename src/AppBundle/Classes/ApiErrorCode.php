<?php
namespace AppBundle\Classes;

class ApiErrorCode
{
    const ERROR_UNKNOWN = 1;
    const ERROR_MISSING_PARAM = 2;
    const ERROR_ACCESS_DENIED = 3;
    const ERROR_USER_EXISTS = 100;
    const ERROR_USER_ABSENT = 101;
    const ERROR_POLICY_IMEI_BLACKLISTED = 200;
    const ERROR_POLICY_USER_BLACKLISTED = 201;
    const ERROR_POLICY_USER_NOT_FOUND = 202;
    const ERROR_POLICY_IMEI_INVALID = 203;
}
