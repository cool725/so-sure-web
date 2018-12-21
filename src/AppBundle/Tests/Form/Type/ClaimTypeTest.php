<?php

namespace AppBundle\Tests\Form\Type;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Listener\BacsListener;
use AppBundle\Service\BacsService;
use AppBundle\Service\ReceperioService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Tests\Extension\Core\Type\FormTypeTest;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 * AppBundle\\Tests\\Form\\Type\\ClaimTypeTest
 */
class ClaimTypeTest extends FormTypeTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    private $reciperoService;

    protected function setUp()
    {
        $this->reciperoService = $this->createMock(ReceperioService::class);
        parent::setUp();
    }

    protected function getExtensions()
    {
        $claim = new ClaimType($this->reciperoService);

        return [
            new PreloadedExtension([$claim], []),
        ];
    }

    public function tearDown()
    {
    }

    public function testClaimTypePrePicsure()
    {
        $user = new User();
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_3);
        $policy->setPolicyTerms($terms);
        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getLowExcess());
        $policy->setPremium($premium);

        $this->assertNotNull($policy->getCurrentExcess());

        $claim = new Claim();
        $claim->setPolicy($policy);

        $form = $this->factory->create(ClaimType::class, $claim);
        $view = $form->createView();
        $children = $view->children;
        $foundDamage = false;
        $foundLoss = false;
        foreach ($children as $key => $value) {
            if ($key == 'type') {
                foreach ($value->vars['choices'] as $item) {
                    if (mb_stripos($item->label, 'damage') !== false) {
                        $this->assertContains('£50', $item->label);
                        $foundDamage = true;
                    }
                    if (mb_stripos($item->label, 'loss') !== false) {
                        $this->assertContains('£70', $item->label);
                        $foundLoss = true;
                    }
                }
                //\Doctrine\Common\Util\Debug::dump($value->vars['choices']);
            }
        }
        $this->assertTrue($foundDamage);
        $this->assertTrue($foundLoss);
    }

    public function testClaimTypeApprovedPicsure()
    {
        $user = new User();
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_4);
        $policy->setPolicyTerms($terms);
        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getHighExcess());
        $premium->setPicSureExcess(PolicyTerms::getLowExcess());
        $policy->setPremium($premium);

        $this->assertNotNull($policy->getCurrentExcess());

        $claim = new Claim();
        $claim->setPolicy($policy);

        $form = $this->factory->create(ClaimType::class, $claim);
        $view = $form->createView();
        $children = $view->children;
        $foundDamage = false;
        $foundLoss = false;
        foreach ($children as $key => $value) {
            if ($key == 'type') {
                foreach ($value->vars['choices'] as $item) {
                    if (mb_stripos($item->label, 'damage') !== false) {
                        $this->assertContains('£50', $item->label);
                        $foundDamage = true;
                    }
                    if (mb_stripos($item->label, 'loss') !== false) {
                        $this->assertContains('£70', $item->label);
                        $foundLoss = true;
                    }
                }
                //\Doctrine\Common\Util\Debug::dump($value->vars['choices']);
            }
        }
        $this->assertTrue($foundDamage);
        $this->assertTrue($foundLoss);
    }

    public function testClaimTypeRejectedPicsure()
    {
        $user = new User();
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_4);
        $policy->setPolicyTerms($terms);
        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getHighExcess());
        $premium->setPicSureExcess(PolicyTerms::getLowExcess());
        $policy->setPremium($premium);

        $this->assertNotNull($policy->getCurrentExcess());

        $claim = new Claim();
        $claim->setPolicy($policy);

        $form = $this->factory->create(ClaimType::class, $claim);
        $view = $form->createView();
        $children = $view->children;
        $foundDamage = false;
        $foundLoss = false;
        foreach ($children as $key => $value) {
            if ($key == 'type') {
                foreach ($value->vars['choices'] as $item) {
                    if (mb_stripos($item->label, 'damage') !== false) {
                        $this->assertContains('£150', $item->label);
                        $foundDamage = true;
                    }
                    if (mb_stripos($item->label, 'loss') !== false) {
                        $this->assertContains('£150', $item->label);
                        $foundLoss = true;
                    }
                }
                //\Doctrine\Common\Util\Debug::dump($value->vars['choices']);
            }
        }
        $this->assertTrue($foundDamage);
        $this->assertTrue($foundLoss);
    }
}
