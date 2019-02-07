<?php

namespace AppBundle\Document;

trait ImeiTrait
{
    public static function generateRandomAppleSerialNumber()
    {
        $serialNumber = [];
        for ($i = 0; $i < 12; $i++) {
            $serialNumber[$i] = rand(0, 9);
        }
        $serialNumber = implode('', $serialNumber);

        // strange bug - only sometimes will return 11 digits
        // TODO - fix this
        if (mb_strlen($serialNumber) != 12) {
            return self::generateRandomAppleSerialNumber();
        }

        return $serialNumber;
    }

    public static function generateRandomImei()
    {
        // First two digits are 0, which means the IMEI isn't registered by any real organisation.
        $imei = [0, 0];
        $time = (int) (floor(microtime(true)) * 1000) + random_int(0, 1000);
        for ($i = 0; $i < 12; $i++) {
            $imei[] = $time % 10;
            $time /= 10;
        }
        $result = ''.self::luhnGenerate(implode($imei));
        while (mb_strlen($result) < 15) {
            // luhnGenerate returns int so leading 0s have to be replaced.
            $result = '0'.$result;
        }
        return $result;
    }

    public static function luhnGenerate($number)
    {
        $stack = 0;
        $digits = str_split(strrev($number), 1);
        foreach ($digits as $key => $value) {
            if ($key % 2 === 0) {
                $value = array_sum(str_split($value * 2, 1));
            }
            $stack += $value;
        }
        $stack %= 10;
        if ($stack !== 0) {
            $stack -= 10;
        }
        return (int) (implode('', array_reverse($digits)) . abs($stack));
    }

    /**
     * @param string $imei
     *
     * @return boolean
     */
    public function isImei($imei)
    {
        return $this->isLuhn($imei) && mb_strlen($imei) == 15;
    }

    /**
     * @see http://stackoverflow.com/questions/4741580/imei-validation-function
     * @param string $n
     *
     * @return boolean
     */
    protected function isLuhn($n)
    {
        if (!is_numeric($n)) {
            return false;
        }

        $str = '';
        foreach (str_split(strrev((string) $n)) as $i => $d) {
            $str .= $i %2 !== 0 ? $d * 2 : $d;
        }
        return array_sum(str_split($str)) % 10 === 0;
    }

    public function isAppleSerialNumber($serialNumber)
    {
        return mb_strlen($serialNumber) == 12;
    }
}
