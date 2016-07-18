<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-nonet
 */
class ApiViewControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testPolicyTerms()
    {
        $crawler = self::$client->request('GET', '/view/policy/terms');
        self::verifyResponse(404);
        $policyKey = self::$client->getContainer()->getParameter('policy_key');
        $url = sprintf('/view/policy/terms?maxPotValue=62.8&policy_key=%s', $policyKey);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }
}
