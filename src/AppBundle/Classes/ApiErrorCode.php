<?php
namespace AppBundle\Classes;

class ApiErrorCode
{
    const ERROR_UNKNOWN = 1;
    const ERROR_MISSING_PARAM = 2;
    const ERROR_USER_EXISTS = 100;
    const ERROR_USER_ABSENT = 101;
}
