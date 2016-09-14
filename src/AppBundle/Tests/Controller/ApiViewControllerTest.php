<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\PhonePolicy;
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

    public function testPolicyTermsPromo()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms', $this),
            'foo'
        );
        $policy = $this->createPolicy($user, true);
        $data = $this->checkPolicy($policy, true);
    }

    public function testPolicyTermsNotPromo()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms-nonpromo', $this),
            'foo'
        );
        $policy = $this->createPolicy($user, false);
        $data = $this->checkPolicy($policy, false);
    }

    private function createPolicy($user, $promo)
    {
        self::addAddress($user);

        $policyTermsRepo = self::$dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policy = new PhonePolicy();
        if ($promo) {
            $policy->setPromoCode('launch');
        }
        $policy->init($user, $latestTerms);
        self::$dm->persist($policy);
        self::$dm->persist($user);
        self::$dm->flush();
        $this->assertNotNull($policy);

        return $policy;
    }

    private function checkPolicy($policy, $promo)
    {
        $policyKey = self::$client->getContainer()->getParameter('policy_key');
        $url = sprintf('/view/policy/%s/terms?maxPotValue=62.8&policy_key=%s', $policy->getId(), $policyKey);
        $crawler = self::$client->request('GET', $url);
        $data = self::verifyResponseHtml(200);
        if ($promo) {
            $this->assertContains('promotion code "LAUNCH"', $data);
        } else {
            $this->assertNotContains('promotion code "LAUNCH"', $data);
        }

        return $data;
    }

    /*
    public function testPolicyTermsDiff()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms-diff', $this),
            'foo'
        );
        $policy = $this->createPolicy($user, true);
        $data = $this->checkPolicy($policy, true);

        $pdf = self::$container->get('templating')->render('AppBundle:Pdf:policyTerms.html.twig', ['policy' => $policy]);

        $data = chunk_split(trim(preg_replace('/\s+/', ' ', strip_tags($data))), 200);
        $pdf = chunk_split(trim(preg_replace('/\s+/', ' ', strip_tags($pdf))), 200);
        $this->assertEquals($data, $pdf);
    }
    */
}
