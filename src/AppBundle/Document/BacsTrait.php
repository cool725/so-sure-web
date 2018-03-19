<?php

namespace AppBundle\Document;

trait BacsTrait
{
    public function normalizeSortCode($sortCode)
    {
        $minusDash = str_replace('-', '', trim($sortCode));
        
        return str_replace(' ', '', $minusDash);
    }

    public function normalizeAccountNumber($accountNumber)
    {
        return str_replace(' ', '', trim($accountNumber));
    }

    public function displayableSortCode($sortCode)
    {
        if ($sortCode && strlen($sortCode) == 6) {
            return sprintf(
                "%s-%s-%s",
                substr($sortCode, 0, 2),
                substr($sortCode, 2, 2),
                substr($sortCode, 4, 2)
            );
        }

        return null;
    }

    public function displayableAccountNumber($accountNumber)
    {
        if ($accountNumber && strlen($accountNumber) == 8) {
            return sprintf("XXXX%s", substr($accountNumber, 4, 4));
        }

        return null;
    }

    public function validateSortCode($sortCode)
    {
        return strlen($this->normalizeSortCode($sortCode)) == 6;
    }

    public function validateAccountNumber($accountNumber, $transformed = true)
    {
        $len = strlen($this->normalizeAccountNumber($accountNumber));
        // Older format can be between 6 & 10 digits apparently
        if ($transformed) {
            return $len == 8;
        } else {
            return $len >=6 && $len <= 10;
        }
    }
}
