<?php

namespace AppBundle\Document;

trait ArrayToApiArrayTrait
{
    public function eachApiArray($array, $debug = false)
    {
        $results = [];
        if ($array) {
            foreach ($array as $item) {
                if ($debug) {
                    $results[] = $item->toApiArray($debug);
                } else {
                    $results[] = $item->toApiArray();
                }
            }
        }

        return $results;
    }
}
