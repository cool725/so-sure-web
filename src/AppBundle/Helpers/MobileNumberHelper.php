<?php

namespace AppBundle\Helpers;

class MobileNumberHelper
{
    private $original;
    private $baseCli;

    /**
     * Before we do anything with the cli, we want to ensure that it is in one
     * standard format that all the other methods can use.
     * The base format for this helper will be no leading 0, no +, no 44
     * e.g. 7700900000
     *
     * MobileNumberHelper constructor.
     *
     * @param string $cli Caller Line Identifier
     */
    public function __construct($cli)
    {
        /**
         * Store the original unchanged so that we can keep a record of it if needed
         */
        $this->original = $cli;
        $this->formatBaseCli();
    }

    /**
     * @param string $cli
     */
    public function setOriginal($cli)
    {
        $this->original = $cli;
        $this->setBaseCli($cli);
    }

    /**
     * @return string
     */
    public function getOriginal(): string
    {
        return $this->original;
    }

    /**
     * @return mixed
     */
    public function getBaseCli()
    {
        return $this->baseCli;
    }

    /**
     * @param mixed $baseCli
     * @return MobileNumberHelper
     */
    public function setBaseCli($baseCli)
    {
        $this->baseCli = $baseCli;
        return $this;
    }

    public function formatBaseCli()
    {
        preg_match('/^(\+?((00)?44|0|)(\d+))$/', $this->original, $matches);
        $this->baseCli = $matches[4];
    }

    /**
     * Now we net a set of methods for returning the number in different formats:
     * standardFormat      = 07700900000
     * mobileFormat        = +447700900000
     * internationalFormat = 00447700900000
     * Simple enough really, just prepend the required prefix onto the baseCli
     */
    public function getStandardFormat()
    {
        return '0' . $this->baseCli;
    }

    public function getMobileFormat()
    {
        return '+44' . $this->baseCli;
    }

    public function getInternationalFormat()
    {
        return '0044' . $this->baseCli;
    }

    /**
     * We also want a method that returns a regex of the exact cli for db lookups
     */
    public function getMongoRegexFormat()
    {
        return "^\+44" . $this->baseCli . "$";
    }
}
