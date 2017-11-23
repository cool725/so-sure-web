<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Phone;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\BaseImeiServiceTest
 */
class QuoteServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $quoteService;
    protected static $rootDir;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$quoteService = self::$container->get('app.quote');
        self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
    }

    private function expect($mailer, $at, $needle)
    {
        $mailer->expects($this->at($at))
            ->method('send')
            ->with($this->callback(
                function ($mail) use ($needle) {
                    return stripos($mail->getBody(), $needle) !== false;
                }
            ));
    }

    public function testQuoteServiceValid()
    {
        $quote = self::$quoteService->getQuotes('One', 'A0001');
        $this->assertTrue((count($quote) > 0));
    }

    public function testQuoteServiceDeviceOnly()
    {
        $quote = self::$quoteService->getQuotes(null, 'A0001');
        $this->assertTrue((count($quote) > 0));
    }

    public function testQuoteServiceDiffererentMake()
    {
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        $this->expect($mailer, 0, 'OnePlus');
        self::$quoteService->setMailerMailer($mailer);
        self::$quoteService->getQuotes('Apple', 'A0001');

    }


    public function testQuoteServiceUnknownDeviceEmail()
    {
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        $this->expect($mailer, 0, 'PlayDevice: One');
        self::$quoteService->setMailerMailer($mailer);
        self::$quoteService->getQuotes(null, 'A0001', 3000);

    }

    public function testQuoteServiceKnownDeviceKnownMemory()
    {
        $quote = self::$quoteService->getQuotes('OnePlus', 'A0001', 15.5);
        $this->assertTrue($quote['deviceFound']);
        $this->assertTrue($quote['memoryFound']);

    }

    public function testQuoteServiceKnownDeviceRooted()
    {
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        $this->expect($mailer, 0, 'Rooted');
        self::$quoteService->setMailerMailer($mailer);
        self::$quoteService->getQuotes('OnePlus', 'A0001', 15.5, true);
    }

    public function testQuoteServiceKnownDeviceIgnoreMake()
    {
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock()->expects($this->never())->method('send');

        self::$quoteService->setMailerMailer($mailer);
        $quote = self::$quoteService->getQuotes('One', 'A0001', 15.5, null, true);
        $this->assertTrue($quote['deviceFound']);
        $this->assertTrue($quote['differentMake']);
    }
}
