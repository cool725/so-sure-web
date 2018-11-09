<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\ValidatorTrait;

/**
 * @group unit
 */
class ValidatorTraitTest extends \PHPUnit\Framework\TestCase
{
    use ValidatorTrait;

    /**
     * Tests validation on empty strings.
     */
    public function testEmptyString()
    {
        $string = "";
        $this->assertEquals($this->conformAlphanumeric($string, 100), "");
        $this->assertEquals($this->conformAlphanumericSpaceDot($string, 100), "");
        $this->assertEquals($this->conformAlphanumericSpaceDotPipe($string, 100), "");
        $this->assertNull($this->conformAlphanumeric($string, 100, 1));
        $this->assertNull($this->conformAlphanumericSpaceDot($string, 100, 1));
        $this->assertNull($this->conformAlphanumericSpaceDotPipe($string, 100, 1));
    }

    /**
     * Tests validation on strings smaller than minimum value.
     */
    public function testSmallerThanMinimum()
    {
        $string = "ab";
        $this->assertNull($this->conformAlphanumeric($string, 100, 5, 1));
        $this->assertNull($this->conformAlphanumericSpaceDot($string, 100, 5, 1));
        $this->assertNull($this->conformAlphanumericSpaceDotPipe($string, 100, 5, 1));
    }

    /**
     * Tests validation on strings smaller than minimum value after conformance.
     */
    public function testSmallerThanMinimumAfterConformance()
    {
        $string = "$$$$$|a b";
        $this->assertNull($this->conformAlphanumeric($string, 100, 5));
        $this->assertNull($this->conformAlphanumericSpaceDot($string, 100, 5));
        $this->assertNull($this->conformAlphanumericSpaceDotPipe($string, 100, 5));
    }

    /**
     * Tests validation on normal strings.
     */
    public function testNormal()
    {
        $string = "$$$$$$$|a| b.ce |$ 5";
        $this->assertEquals("abce5", $this->conformAlphanumeric($string, 100));
        $this->assertEquals("a b.ce  5", $this->conformAlphanumericSpaceDot($string, 100));
        $this->assertEquals("|a| b.ce | 5", $this->conformAlphanumericSpaceDotPipe($string, 100));
    }

    /**
     * Tests validation on strings that are larger than maximum value before conformance.
     */
    public function testLargerThanMaximumBeforeConformance()
    {
        $string = "$$$$$$|a| b.ce |$ 5";
        $this->assertEquals("abce5", $this->conformAlphanumeric($string, 5));
        $this->assertEquals("a b.ce  5", $this->conformAlphanumericSpaceDot($string, 10));
        $this->assertEquals("|a| b.ce |", $this->conformAlphanumericSpaceDotPipe($string, 10));
    }

    /**
     * Tests validation on strings that are larger than maximum value.
     */
    public function testLargerThanMaximum()
    {
        $string = "$$$$$$$|a| b.ce |$ 5";
        $this->assertEquals("abc", $this->conformAlphanumeric($string, 3));
        $this->assertEquals("a b", $this->conformAlphanumericSpaceDot($string, 3));
        $this->assertEquals("|a|", $this->conformAlphanumericSpaceDotPipe($string, 3));
    }
}
