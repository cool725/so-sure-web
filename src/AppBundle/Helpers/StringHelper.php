<?php

namespace AppBundle\Helpers;

use AppBundle\Classes\NoOp;

/**
 * Provides static functions for making csvs.
 */
class StringHelper
{
    /**
     * Just takes a bunch of strings and joins them because with our combination of standards checkers it is impossible
     * to write long strings without turning off standards checking or maybe using heredoc.
     * @param mixed ..$item is all the strings to join.
     * @return string the joined strings as one string.
     */
    public static function join(...$item)
    {
        NoOp::ignore($item);
        return implode('', func_get_args());
    }
}
