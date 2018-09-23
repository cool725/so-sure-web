<?php

namespace AppBundle\Tests\Service;

use AppBundle\Service\SixpackService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Controller\OpsController;

/**
 * @group unit
 */
class SixpackServiceUnitTest extends \PHPUnit\Framework\TestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function testUnauthExperimentNames()
    {
        foreach (SixpackService::$unauthExperiments as $exp) {
            $this->assertNotContains($exp, SixpackService::$archivedExperiments);
        }
    }

    public function testAuthExperimentNames()
    {
        foreach (SixpackService::$authExperiments as $exp) {
            $this->assertNotContains($exp, SixpackService::$archivedExperiments);
        }
    }
}
