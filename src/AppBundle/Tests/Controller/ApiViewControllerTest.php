<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Controller\\ApiViewControllerTest
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
        $policyKey = $this->getContainer(true)->getParameter('policy_key');
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

        $latestTerms = $this->getLatestPolicyTerms($this->getDocumentManager());

        $policy = new HelvetiaPhonePolicy();
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
        $policyKey = $this->getContainer(true)->getParameter('policy_key');
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
        foreach (PolicyTerms::$allVersions as $versionName => $version) {
            $count++;
            $terms = $policyTermsRepo->findOneBy(['version' => $versionName]);
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
            if (in_array($versionName, [PolicyTerms::VERSION_9])) {
                $claimDefaultDirectGroup = false;
            }

            $templating = self::$container->get('templating');
            $pdf = $templating->render(
                sprintf('AppBundle:Pdf:policyTermsV%d.html.twig', PolicyTerms::getVersionNumberByVersion($versionName)),
                ['policy' => $policy, 'claims_default_direct_group' => $claimDefaultDirectGroup]
            );

            $debug = false;
            //$debug = true;
            $this->verifyTerms($data, $pdf, $version, $versionName, $debug);
        }
    }

    private function verifyTerms($data, $pdf, $version, $versionName, $debug = false)
    {
        $this->assertContains('<body>', $data);
        // remove tags
        $data = strip_tags($data);
        $pdf = strip_tags($pdf);
        // adjust for differences in files
        // @codingStandardsIgnoreStart
        $data = trim(preg_replace('/\s+/', ' ', $data));
        $pdf = trim(preg_replace('/\s+/', ' ', $pdf));
        $pdf = str_replace('p {display: block;}', '', $pdf);
        $pdf = str_replace('•', '', $pdf);
        $pdf = str_replace('&nbsp;', '', $pdf);
        $data = str_replace('£60.00', '£60', $data);
        $pdf = str_replace(' (contact details on page 6)', '', $pdf);
        $data = str_replace(' (contact details on page 6)', '', $data);
        $pdf = str_replace('for ?', 'for?', $pdf);
        $data = str_replace('for ?', 'for?', $data);
        $pdf = str_replace('Please note that replacement mobile phones may be from refurbished stock.', '', $pdf);
        $data = str_replace('Please note that replacement mobile phones may be from refurbished stock.', '', $data);
        $pdf = str_replace('Basic definitions', 'Basic definition', $pdf);
        $data = str_replace('Basic definitions', 'Basic definition', $data);
        $pdf = str_replace('body { font-family: "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; font-size: 8pt; } ', '', $pdf);
        $pdf = str_replace('Loss Up to', 'Up to', $pdf);
        $data = str_replace('Loss Up to', 'Up to', $data);
        $pdf = str_replace('.SALVA', '. SALVA', $pdf);
        $data = str_replace('.SALVA', '. SALVA', $data);
        $pdf = str_replace('knowled If', 'knowledge. Failure to do so may affect the validity of your policy or the payment of your claim. If', $pdf);
        $data = str_replace('knowled If', 'knowledge. Failure to do so may affect the validity of your policy or the payment of your claim. If', $data);
        $pdf = str_replace('”', '"', $pdf);
        $pdf = str_replace('“', '"', $pdf);
        $data = str_replace('”', '"', $data);
        $data = str_replace('“', '"', $data);
        $data = trim(preg_replace('/\s+/', ' ', $data));
        $pdf = trim(preg_replace('/\s+/', ' ', $pdf));

        // top and bottom of api is slightly different - best to add to pdf version to avoid replacing unindented areas
        $pdf = sprintf('so-sure Policy Document %s', $pdf);
        if ($version >= 11) {
            $pdf = sprintf('%s Contact details Address: so-sure Limited, 5 Martin Lane, London EC4R 0DP Email: support@wearesosure.com', $pdf);
        } else {
            $pdf = sprintf('%s Contact details Address: so-sure Limited, 10 Finsbury Square, London EC2A 1AF Email: support@wearesosure.com', $pdf);
        }
        // @codingStandardsIgnoreEnd
        // Add version name and chunk into bits
        $data = sprintf('%s%s', $data, $versionName);
        $pdf = sprintf('%s%s', $pdf, $versionName);
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
        $body = $this->getClientResponseContent();

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
        $body = $this->getClientResponseContent();

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
        $body = $this->getClientResponseContent();

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
        $body = $this->getClientResponseContent();

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
        $body = $this->getClientResponseContent();

        $this->assertFalse(mb_stripos($body, 'h1'));
        $this->assertTrue(mb_stripos($body, 'h4') >= 0);
    }
}
