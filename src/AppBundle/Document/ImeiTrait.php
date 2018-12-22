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
        $imei = [];
        for ($i = 0; $i < 14; $i++) {
            $imei[$i] = random_int(0, 9);
        }

        $result = self::luhnGenerate(implode($imei));

        // strange bug - only sometimes will return 14 digits
        // TODO - fix this
        if (mb_strlen($result) != 15) {
            return self::generateRandomImei();
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
