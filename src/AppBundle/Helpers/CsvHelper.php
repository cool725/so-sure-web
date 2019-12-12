<?php

namespace Helper;

/**
 * Provides static functions for making csvs.
 */
class CsvHelper
{
    /**
     * Makes a line of the csv with quotes around the items and commas between them.
     * @param mixed ...$item are all of the string items to concatenate of variable number.
     */
    public function line(...$item)
    {
        return '"'.implode('","', func_get_args()).'"';
    }
}
