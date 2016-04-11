<?php
namespace AppBundle\Classes;

class ApiErrorCode
{
    const SUCCESS = 0;
    const ERROR_UNKNOWN = 1;
    const ERROR_MISSING_PARAM = 2;
    const ERROR_ACCESS_DENIED = 3;
    const ERROR_NOT_FOUND = 4;
    const ERROR_UPGRADE_APP = 5;
    const ERROR_USER_EXISTS = 100;
    const ERROR_USER_ABSENT = 101;
    const ERROR_POLICY_IMEI_BLACKLISTED = 200;
    const ERROR_POLICY_USER_BLACKLISTED = 201;
    const ERROR_POLICY_USER_NOT_FOUND = 202;
    const ERROR_POLICY_IMEI_INVALID = 203;
    const ERROR_POLICY_INVALID_ACCOUNT_NAME = 204;
    const ERROR_POLICY_INVALID_USER_DETAILS = 205;
    const ERROR_INVITATION_DUPLICATE = 300;
    const ERROR_INVITATION_OPTOUT = 301;
}
