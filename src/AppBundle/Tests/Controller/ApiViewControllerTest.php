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
class ApiViewControllerTest extends BaseApiControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    protected static $policyKey;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        self::$policyKey = static::$container->getParameter('policy_key');
    }

    public function testPolicyTerms()
    {
        $crawler = self::$client->request('GET', '/view/policy/terms');
        self::verifyResponse(404);
        $policyKey = self::$client->getContainer()->getParameter('policy_key');
        $url = sprintf('/view/policy/terms?maxPotValue=62.8&policy_key=%s', $policyKey);
        $crawler = self::$client->request('GET', $url);
        $data = self::verifyResponseHtml(200);
        $this->assertContains('<body>', $data);
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
        /** @var PolicyTerms $latestTerms */
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

    public function testPolicyTermsDiffs()
    {
        $policyTermsRepo = static::$dm->getRepository(PolicyTerms::class);
        $count = 0;
        foreach (PolicyTerms::$allVersions as $version) {
            $count++;
            $terms = $policyTermsRepo->findOneBy(['version' => $version]);
            $user = self::createUser(
                self::$userManager,
                self::generateEmail(sprintf('policy-terms-diff-v%d', $count), $this),
                'foo'
            );
            $policy = $this->createPolicy($user, true);
            $policy->setPolicyTerms($terms);
            self::$dm->flush();
            $data = $this->checkPolicy($policy, true);

            // TODO: Probably won't carry davies through to future version, but see how that pans out
            $claimDefaultDirectGroup = false;
            if (in_array($version, [PolicyTerms::VERSION_9])) {
                $claimDefaultDirectGroup = false;
            }

            $templating = self::$container->get('templating');
            $pdf = $templating->render(
                sprintf('AppBundle:Pdf:policyTermsV%d.html.twig', PolicyTerms::getVersionNumberByVersion($version)),
                ['policy' => $policy, 'claims_default_direct_group' => $claimDefaultDirectGroup]
            );

            $this->verifyTerms($data, $pdf);
            //$this->verifyTerms($data, $pdf, true);
        }
    }

    private function verifyTerms($data, $pdf, $debug = false)
    {
        $this->assertContains('<body>', $data);
        // remove tags
        $data = strip_tags($data);
        $pdf = strip_tags($pdf);

        // adjust for differences in files
        $pdf = str_replace('p {display: block;}', '', $pdf);
        $pdf = str_replace('•', '', $pdf);
        $pdf = str_replace('&nbsp;', '', $pdf);

        $data = str_replace('£60.00', '£60', $data);

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

        if ($debug) {
            /* If changes do occur, useful for running a diff */
            file_put_contents('/vagrant/terms-api.txt', $data);
            file_put_contents('/vagrant/terms-pdf.txt', $pdf);
            //print 'meld /var/sosure/terms-api.txt /var/sosure/terms-pdf.txt';
        }

        $this->assertEquals($data, $pdf);
    }

    /**
     *
     */
    public function testGetPolicyTermsHtml()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms-html', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf(
            '/view/policy/%s/terms?policy_key=%s&maxPotValue=0&yearlyPremium=85.80',
            $policyId,
            static::$policyKey
        );
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        $body = self::$client->getResponse()->getContent();

        $this->assertTrue(mb_stripos($body, 'h1') >= 0);
        $this->assertFalse(mb_stripos($body, 'h4'));
    }

    /**
     *
     */
    public function testGetPolicyTerms2Html()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms2-html', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf(
            '/view/policy/v2/%s/terms?policy_key=%s&maxPotValue=0&yearlyPremium=85.80',
            $policyId,
            static::$policyKey
        );
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        $body = self::$client->getResponse()->getContent();

        $this->assertFalse(mb_stripos($body, 'h1'));
        $this->assertTrue(mb_stripos($body, 'h4') >= 0);
    }

    /**
     *
     */
    public function testGetLatestPolicyTermsHtmlH1()
    {
        $url = sprintf('/view/policy/terms?policy_key=%s&maxPotValue=62.8&noH1=0', static::$policyKey);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        $body = self::$client->getResponse()->getContent();

        $this->assertTrue(mb_stripos($body, 'h1') >= 0);
        $this->assertFalse(mb_stripos($body, 'h4'));
    }

    /**
     *
     */
    public function testGetLatestPolicyTermsHtmlNoH1()
    {
        $url = sprintf('/view/policy/terms?policy_key=%s&maxPotValue=62.8&noH1=1', static::$policyKey);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        $body = self::$client->getResponse()->getContent();

        $this->assertFalse(mb_stripos($body, 'h1'));
        $this->assertTrue(mb_stripos($body, 'h4') >= 0);
    }

    /**
     *
     */
    public function testGetLatestPolicyTerms2Html()
    {
        $url = sprintf('/view/policy/v2/terms?policy_key=%s&maxPotValue=62.8', static::$policyKey);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        $body = self::$client->getResponse()->getContent();

        $this->assertFalse(mb_stripos($body, 'h1'));
        $this->assertTrue(mb_stripos($body, 'h4') >= 0);
    }
}
