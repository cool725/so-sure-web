<?php

namespace AppBundle\Document;

trait ArrayToApiArrayTrait
{
    /**
     * a bit crap - should be array - TODO Refactor
     */
    public function eachApiArray($array, $param1 = null, $param2 = null)
    {
        $results = [];
        if ($array) {
            foreach ($array as $item) {
                if ($param2 !== null) {
                    $results[] = $item->toApiArray($param1, $param2);
                } elseif ($param1 !== null) {
                    $results[] = $item->toApiArray($param1);
                } else {
                    $results[] = $item->toApiArray();
                }
            }
        }

        return $results;
    }

    public function eachApiMethod($array, $method, $isDate = true, $param1 = null)
    {
        $results = [];
        if ($array) {
            foreach ($array as $item) {
                if ($param1) {
                    $result = call_user_func([$item, $method], $param1);
                } else {
                    $result = call_user_func([$item, $method]);
                }
                if ($result) {
                    if ($isDate) {
                        $results[] = $result->format(\DateTime::ATOM);
                    } else {
                        $results[] = $result;
                    }
                }
            }
        }

        return $results;
    }
}
