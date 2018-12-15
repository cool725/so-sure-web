<?php

namespace AppBundle\Tests\DataFixtures;

use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Exception\ValidationException;
use AppBundle\Tests\DataFixtures\AdditionsInvalidModel;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 */
class AdditionalInvalidModelTest extends WebTestCase
{
    use DateTrait;
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;

    protected static $container;
    protected static $invitationService;
    /** @var DocumentManager */
    protected static $dm;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
    }

    /**
     * @expectedException \AppBundle\Exception\ValidationException
     */
    public function testAdditionsInvalidModel()
    {
        $model = new AdditionsInvalidModel();
        $model->setExpectedImportException();
        $model->setContainer(static::$container);
        $model->load(static::$dm);
    }
}
