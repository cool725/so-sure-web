<?php
namespace AppBundle\Interfaces;

interface EqualsInterface
{
    /**
     * @param mixed $object
     * @return boolean|null
     */
    public function equals($object);
}
