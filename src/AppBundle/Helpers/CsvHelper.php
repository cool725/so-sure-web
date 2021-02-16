<?php

namespace AppBundle\Helpers;

use AppBundle\Classes\NoOp;

/**
 * Provides static functions for making csvs.
 */
class CsvHelper
{
    /**
     * Makes a line of the csv with quotes around the items and commas between them.
     * @param mixed ...$item are all of the string items to concatenate of variable number.
     */
    public static function line(...$item)
    {
        // Item is used kind of since it is the varargs, phpstan is just foolish.
        NoOp::ignore($item);
        return '"'.implode('","', func_get_args()).'"';
    }

    /**
     * Creates an array out of all arguments that are not null.
     * @param mixed ...$item are all of the items to put in the array (other than the null ones).
     * @return array with no nulls.
     */
    public static function ignoreBlank(...$item)
    {
        NoOp::ignore($item);
        $items = [];
        foreach (func_get_args() as $arg) {
            if ($arg !== null) {
                $items[] = $arg;
            }
        }
        return $items;
    }
}
