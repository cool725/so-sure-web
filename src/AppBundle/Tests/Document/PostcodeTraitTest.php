<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PostcodeTrait;

/**
 * @group unit
 */
class PostcodeTraitTest extends \PHPUnit\Framework\TestCase
{
    use PostcodeTrait;

    /**
     * Makes sure that find postcode finds postcodes.
     */
    public function testFindPostcode()
    {
        $this->assertTrue(PostcodeTrait::findPostcode('RM1 2DX, a nice place', 'RM12DX'));
        $this->assertTrue(PostcodeTrait::findPostcode('RM1 2DX, a nice place', 'RM1 2DX'));
        $this->assertTrue(PostcodeTrait::findPostcode('RM12DX, a nice place', 'rm1 2dx'));
        $this->assertTrue(PostcodeTrait::findPostcode('urban deviancy, rm12dx', 'rm1 2dx'));
        $this->assertTrue(PostcodeTrait::findPostcode('urban deviancy, E153DL', 'e153dl'));
        $this->assertFalse(PostcodeTrait::findPostcode('RM1 2DX, a nice place', 'E153DL'));
    }
}
