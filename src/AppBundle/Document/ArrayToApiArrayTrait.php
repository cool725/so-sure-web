<?php

namespace AppBundle\Document;

trait ArrayToApiArrayTrait
{
    public function eachApiArray($array, $param = null)
    {
        $results = [];
        if ($array) {
            foreach ($array as $item) {
                if ($param !== null) {
                    $results[] = $item->toApiArray($param);
                } else {
                    $results[] = $item->toApiArray();
                }
            }
        }

        return $results;
    }

    public function eachApiMethod($array, $method, $isDate = true)
    {
        $results = [];
        if ($array) {
            foreach ($array as $item) {
                $result = call_user_func([$item, $method]);
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
