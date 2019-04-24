<?php
/**
 * Copyright (c) So-Sure 2019.
 * @author Blake Payne <blake@so-sure.com>
 */

namespace AppBundle\Tests\Service;

use AppBundle\Document\PhonePolicy;
use AppBundle\Form\Type\PolicySearchType;
use AppBundle\Service\SearchService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SearchServiceTest extends WebTestCase
{
    /**
     * @var ContainerInterface
     */
    protected static $container;

    private static $searchService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        self::$searchService = self::$container->get('app.search');
    }

    /**
     * @expectedException AppBundle\Exception\MissingDependencyException
     * @expectedExceptionMessage The form has not been set, please set the form before continuing
     */
    public function testGetFormNotSet()
    {
        self::$searchService->getForm();
    }

    private function createForm()
    {
        return self::$container->get('form.factory')->create(PolicySearchType::class, null, ['method' => 'GET']);
    }

    public function testSetForm()
    {
        $set = self::$searchService->setForm($this->createForm());
        self::assertInstanceOf(SearchService::class, $set, "The type is correct");
    }

    public function testGetForm()
    {
        self::$searchService->setForm($this->createForm());
        $form = self::$searchService->getForm();
        self::assertInstanceOf(PolicySearchType::class, $form, "The type is correct");
    }

    private function setRequest()
    {
        $policy_search =
            [
                "firstname" => "",
                "lastname" => "",
                "mobile" => "",
                "email" => "",
                "postcode" => "",
                "policy" => "",
                "status" => "1",
                "imei" => "",
                "facebookId" => "",
                "sosure" => "",
                "serial" => "",
                "id" => "",
                "phone" => "",
                "paymentMethod" => "",
                "bacsReference" => "",
                "invalid" => "1",
                "search" => "",
            ];
        return $policy_search;
    }

    public function testSearchPolicies()
    {
        $form = $this->createForm();
        self::$searchService->setForm($form);
        $form->submit($this->setRequest());
        $result = self::$searchService->searchPolicies();
        foreach ($result as $policy) {
            self::assertInstanceOf(PhonePolicy::class, $policy);
        }
    }
}
