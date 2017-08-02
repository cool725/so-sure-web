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
        $data = self::verifyResponseHtml(200);
        $this->assertNotContains('promotion code "LAUNCH"', $data);
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
        $url = sprintf('/view/policy/%s/terms?maxPotValue=48&policy_key=%s', $policy->getId(), $policyKey);
        $crawler = self::$client->request('GET', $url);
        $data = self::verifyResponseHtml(200);
        if ($promo) {
            $this->assertContains('promotion code "LAUNCH"', $data);
        } else {
            $this->assertNotContains('promotion code "LAUNCH"', $data);
        }

        return $data;
    }

    public function testPolicyTermsDiffV1()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms-diff', $this),
            'foo'
        );
        $policy = $this->createPolicy($user, true);
        $data = $this->checkPolicy($policy, true);

        $templating = self::$container->get('templating');
        $pdf = $templating->render('AppBundle:Pdf:policyTermsV1.html.twig', ['policy' => $policy]);

        // remove tags
        $data = strip_tags($data);
        $pdf = strip_tags($pdf);

        // adjust for differences in files
        $pdf = str_replace('p {display: block;}', '', $pdf);
        $pdf = str_replace('•', '', $pdf);
        $pdf = str_replace('&nbsp;', '', $pdf);

        // top and bottom of api is slightly different - best to add to pdf version to avoid replacing unindented areas
        $pdf = sprintf('so-sure Policy Document%s', $pdf);
        // @codingStandardsIgnoreStart
        $pdf = sprintf('%s Contact details Address: so-sure Limited, 10 Finsbury Square, London EC2A 1AF Email: support@wearesosure.com', $pdf);
        // @codingStandardsIgnoreEnd

        // delete extra spaces, and chunk into 200 chars to make comparision easier
        $data = trim(preg_replace('/\s+/', ' ', $data));
        $pdf = trim(preg_replace('/\s+/', ' ', $pdf));
        $data = chunk_split($data, 200);
        $pdf = chunk_split($pdf, 200);

        /* If changes do occur, useful for running a diff
        file_put_contents('/vagrant/terms-api.txt', $data);
        file_put_contents('/vagrant/terms-pdf.txt', $pdf);
        print 'meld /var/sosure/terms-api.txt /var/sosure/terms-pdf.txt';
        */

        $this->assertEquals($data, $pdf);
    }

    public function testPolicyTermsDiffV2()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms-diff-v2', $this),
            'foo'
        );
        $policy = $this->createPolicy($user, true);
        $data = $this->checkPolicy($policy, true);

        $templating = self::$container->get('templating');
        $pdf = $templating->render('AppBundle:Pdf:policyTermsV2.html.twig', ['policy' => $policy]);

        // remove tags
        $data = strip_tags($data);
        $pdf = strip_tags($pdf);

        // adjust for differences in files
        $pdf = str_replace('p {display: block;}', '', $pdf);
        $pdf = str_replace('•', '', $pdf);
        $pdf = str_replace('&nbsp;', '', $pdf);

        // top and bottom of api is slightly different - best to add to pdf version to avoid replacing unindented areas
        $pdf = sprintf('so-sure Policy Document%s', $pdf);
        // @codingStandardsIgnoreStart
        $pdf = sprintf('%s Contact details Address: so-sure Limited, 10 Finsbury Square, London EC2A 1AF Email: support@wearesosure.com', $pdf);
        // @codingStandardsIgnoreEnd

        // delete extra spaces, and chunk into 200 chars to make comparision easier
        $data = trim(preg_replace('/\s+/', ' ', $data));
        $pdf = trim(preg_replace('/\s+/', ' ', $pdf));
        $data = chunk_split($data, 200);
        $pdf = chunk_split($pdf, 200);

        /* If changes do occur, useful for running a diff
        file_put_contents('/vagrant/terms-api.txt', $data);
        file_put_contents('/vagrant/terms-pdf.txt', $pdf);
        print 'meld /var/sosure/terms-api.txt /var/sosure/terms-pdf.txt';
        */

        $this->assertEquals($data, $pdf);
    }
}
