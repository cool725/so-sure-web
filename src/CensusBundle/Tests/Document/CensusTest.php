<?php
namespace CensusBundle\Tests\Document;

use CensusBundle\Document\Census;
use PHPUnit\Framework\TestCase;

class CensusTest extends TestCase
{
    /** @var Census */
    private $census;

    public function setUp()
    {
        $this->census = new Census();
    }

    public function testGetSuperGroup()
    {
        $this->setProtectedProperty('sprgrp', '8');
        $this->assertSame('Hard-pressed living', $this->census->getSuperGroup());

        $this->setProtectedProperty('sprgrp', '99999');
        $this->assertNull($this->census->getSuperGroup());
    }

    public function testGetGroup()
    {
        $this->setProtectedProperty('grp', '3d');
        $this->assertSame('Aspirational techies', $this->census->getGroup());

        $this->setProtectedProperty('grp', '-unknown-');
        $this->assertNull($this->census->getGroup());
    }

    public function testGetSubgrp()
    {
        $this->setProtectedProperty('subgrp', '2d3');
        $this->assertSame('EU white-collar workers', $this->census->getSubGroup());

        $this->setProtectedProperty('subgrp', '-unknown-');
        $this->assertSame('', $this->census->getSubGroup());
    }

    private function setProtectedProperty($name, $value)
    {
        $reflectionClass = new \ReflectionClass($this->census);

        $reflectionProperty = $reflectionClass->getProperty($name);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->census, $value);
    }
}
