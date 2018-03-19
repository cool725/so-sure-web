<?php
namespace AppBundle\Exception;

class DirectDebitBankException extends \Exception
{
    const ERROR_UNKNOWN = 0;
    const ERROR_SORT_CODE = 1;
    const ERROR_ACCOUNT_NUMBER = 2;
    const ERROR_NON_DIRECT_DEBIT = 3;
}
