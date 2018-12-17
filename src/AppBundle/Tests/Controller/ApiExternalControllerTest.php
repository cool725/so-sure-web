<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Classes\GoCompare;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @group functional-net
 * AppBundle\\Tests\\Controller\\ApiExternalControllerTest
 */
class ApiExternalControllerTest extends BaseApiControllerTest
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 8', 'memory' => 64]);
    }

    // zendesk

    /**
     *
     */
    public function testZendeskOk()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );
        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => $user->getId()]
        );
        
        $data = $this->verifyResponse(200);
        $this->assertTrue(mb_strlen($data['jwt']) > 20);
    }

    public function testZendeskUserNotFound()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-notfound', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => '12']
        );

        $data = $this->verifyResponse(401, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testZendeskInvalidIp()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-invalidip', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => $user->getId()]
        );

        $data = $this->verifyResponse(401, ApiErrorCode::ERROR_ACCESS_DENIED);
    }

    public function testZendeskMissingUserToken()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-missingtoken', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            []
        );

        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testZendeskInvalidToken()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-token', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?&debug=true'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => $user->getId()]
        );

        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testIntercomValidPing()
    {
        $data = '{
  "type" : "notification_event",
  "app_id" : "hp8z6qfh",
  "data" : {
    "type" : "notification_event_data",
    "item" : {
      "type" : "ping",
      "message" : "something something interzen"
    }
  },
  "links" : { },
  "id" : null,
  "topic" : "ping",
  "delivery_status" : null,
  "delivery_attempts" : 1,
  "delivered_at" : 0,
  "first_sent_at" : 1476349292,
  "created_at" : 1476349292,
  "self" : null
}';
        $url = sprintf(
            '/external/intercom'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
                "HTTP_X-Hub-Signature" => "sha1=0c0f352a9fd5b775746baca1858a1d11ef31fb24"
            ),
            $data
        );

        $data = $this->verifyResponse(200);
    }

    public function testIntercomInvalidHashPing()
    {
        $data = '{
  "type" : "notification_event",
  "app_id" : "hp8z6qfh",
  "data" : {
    "type" : "notification_event_data",
    "item" : {
      "type" : "ping",
      "message" : "something something interzen"
    }
  },
  "links" : { },
  "id" : null,
  "topic" : "ping",
  "delivery_status" : null,
  "delivery_attempts" : 1,
  "delivered_at" : 0,
  "first_sent_at" : 1476349292,
  "created_at" : 1476349292,
  "self" : null
}';
        $url = sprintf(
            '/external/intercom'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
                "HTTP_X-Hub-Signature" => "sha1=baca1858a1d11ef31fb24"
            ),
            $data
        );

        $data = $this->verifyResponse(500);
    }

    public function testIntercomValidUnsubUser()
    {
        $repo = self::$dm->getRepository(EmailOptOut::class);
        $optouts = $repo->findBy(['email' => 'patrick@so-sure.com']);
        $this->assertEquals(0, count($optouts));

        $data = '{
  "type" : "notification_event",
  "app_id" : "hp8z6qfh",
  "data" : {
    "type" : "notification_event_data",
    "item" : {
      "type" : "user",
      "id" : "57fe33f79d67ab3ae104abb2",
      "user_id" : "57fe33036362391a20566723",
      "anonymous" : false,
      "email" : "patrick@so-sure.com",
      "name" : "Patrick McAndrew",
      "pseudonym" : "Grey Rocket",
      "avatar" : {
        "type" : "avatar",
        "image_url" : "https://secure.gravatar.com/avatar/4b60bb2ad56bc22e93add85f5846b052?s=24&d=identicon"
      },
      "app_id" : "hp8z6qfh",
      "companies" : {
        "type" : "company.list",
        "companies" : [ ]
      },
      "location_data" : { },
      "last_request_at" : null,
      "last_seen_ip" : null,
      "created_at" : "2016-10-12T13:00:39.992Z",
      "remote_created_at" : "2016-10-12T12:56:35.000Z",
      "signed_up_at" : "2016-10-12T12:56:35.000Z",
      "updated_at" : "2016-10-14T10:48:30.838Z",
      "session_count" : 0,
      "social_profiles" : {
        "type" : "social_profile.list",
        "social_profiles" : [ ]
      },
      "unsubscribed_from_emails" : true,
      "user_agent_data" : null,
      "tags" : {
        "type" : "tag.list",
        "tags" : [ ]
      },
      "segments" : {
        "type" : "segment.list",
        "segments" : [ ]
      },
      "custom_attributes" : {
        "premium" : 101.88,
        "pot" : 0,
        "connections" : 17,
        "promo_code" : null
      }
    }
  },
  "links" : { },
  "id" : "notif_06234660-91fc-11e6-89fb-1189877c22cb",
  "topic" : "user.unsubscribed",
  "delivery_status" : "pending",
  "delivery_attempts" : 1,
  "delivered_at" : 0,
  "first_sent_at" : 1476442230,
  "created_at" : 1476442230,
  "self" : null
}';
        $url = sprintf(
            '/external/intercom'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
                "HTTP_X-Hub-Signature" => "sha1=45142bb1159d87162867a60aa85eea657d2efd5c"
                ),
            $data
        );

        $data = $this->verifyResponse(200);

        $repo = self::$dm->getRepository(EmailOptOut::class);
        /** @var EmailOptOut $optout */
        $optout = $repo->findOneBy(['email' => 'patrick@so-sure.com']);
        $this->assertNotNull($optout);

        $this->assertTrue(in_array(EmailOptOut::OPTOUT_CAT_MARKETING, $optout->getCategories()));
        $this->assertEquals(EmailOptOut::OPT_LOCATION_INTERCOM, $optout->getLocation());
    }

    public function testMixpanelDelete()
    {
        $this->clearRateLimit();
        $url = sprintf(
            '/external/mixpanel/delete?mixpanel_webhook_key=%s&debug=true',
            static::$container->getParameter('mixpanel_webhook_key')
        );

        // @codingStandardsIgnoreStart
        $crawler =  static::$client->request(
            "POST",
            $url,
            ['users' => '[{"$distinct_id": "5881f7d2b660af53781c0361", "$properties": {"Final Monthly Cost": 6.49, "Number of Connections": 1, "$country_code": "IE", "Device Insured": "WileyFox Swift 2 (16 GB)", "$region": "Leinster", "Date of Birth": "1993-02-12T00:00:00", "$email": "ted+a@so-surr.com", "OS": "Cyanogen", "$last_name": "Aaa", "Payment Option": "monthly", "Number of Payments Received": 1, "Billing Address": "so-sure Test Address Line 1 so-sure Test Address Line 2 so-sure Test Address Line 3 so-sure Test City BX1 1LT", "$city": "Dublin", "$first_name": "Gh", "$last_seen": "2017-01-20T11:44:38", "$timezone": "Europe/Dublin", "Number of Invites Sent": 1, "$phone": "+447963123456", "Reward Pot Value": "10.00"}}, {"$distinct_id": "58872649b660af3b5f37d0e2", "$properties": {"Final Monthly Cost": 8.68, "Number of Connections": 1, "$country_code": "IE", "Device Insured": "Apple iPhone SE (16 GB)", "$region": "Leinster", "Date of Birth": "1999-01-24T00:00:00", "$email": "julien+3@so-sure.com", "OS": "iOS", "$last_name": "Bla", "Payment Option": "yearly", "Number of Payments Received": 1, "Billing Address": "so-sure Test Address Line 1 so-sure Test Address Line 2 so-sure Test Address Line 3 so-sure Test City BX1 1LT", "$city": "Dublin", "$first_name": "Ella", "$last_seen": "2017-01-24T11:14:29", "$timezone": "Europe/Dublin", "Number of Invites Sent": 1, "$phone": "+447865432357", "Reward Pot Value": "10.00"}}, {"$distinct_id": "5880eba6b660af370903e0d2", "$properties": {"$country_code": "IE", "Number Of Logins": 2, "$region": "Leinster", "$email": "patrick@so-sure.com", "$last_name": "McAndrew", "First Monthly Cost": 6.52, "$city": "Dublin", "$first_name": "Patrick", "$last_seen": "2017-01-24T14:37:14", "First Device Selected": "HTC Desire 510 (8 GB)", "$timezone": "Europe/Dublin"}}, {"$distinct_id": "0599296a-3688-4ce2-971f-2f1b77b09887", "$properties": {"$country_code": "IE", "$region": "Leinster", "First Monthly Cost": 8.53, "$city": "Dublin", "$last_seen": "2017-01-24T15:47:40", "First Device Selected": "LG G5 (32 GB)", "$timezone": "Europe/Dublin"}}, {"$distinct_id": "58874744b660af425e515531", "$properties": {"Final Monthly Cost": 8.68, "Number of Connections": 1, "$country_code": "IE", "Device Insured": "Apple iPhone SE (16 GB)", "$region": "Leinster", "Date of Birth": "1999-01-24T00:00:00", "$email": "julien+5@so-sure.com", "OS": "iOS", "$last_name": "Bla", "Payment Option": "monthly", "Number of Payments Received": 1, "Billing Address": "so-sure Test Address Line 1 so-sure Test Address Line 2 so-sure Test Address Line 3 so-sure Test City BX1 1LT", "$city": "Dublin", "$first_name": "Bla", "$last_seen": "2017-01-24T12:34:33", "$timezone": "Europe/Dublin", "Number of Invites Sent": 1, "$phone": "+447856456765", "Reward Pot Value": "10.00"}}]']
        );
        // @codingStandardsIgnoreEnd

        $data = $this->verifyResponse(200);
    }

    public function testMixpanelDeleteInvalidToken()
    {
        $this->clearRateLimit();
        $url = sprintf(
            '/external/mixpanel/delete?debug=true'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['users' => 'a']
        );

        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    // gocompare

    /**
     *
     */
    public function testGoCompareFeed()
    {
        $gadgets = [];
        foreach (GoCompare::$models as $id => $details) {
            $gadgets[] = ['gadget' => ['gadget_id' => $id, 'loss_cover' => true]];
        }

        $data = ['request' => ['gadgets' => $gadgets]];
        /*
        $data = '{"request": {
  "gadgets" : [
    {"gadget": {"gadget_id": 20, "loss_cover": true}},
    {"gadget": {"gadget_id": 21, "loss_cover": true}},
    {"gadget": {"gadget_id": 22, "loss_cover": true}},
    {"gadget": {"gadget_id": 23, "loss_cover": true}},
    {"gadget": {"gadget_id": 24, "loss_cover": true}},
    {"gadget": {"gadget_id": 25, "loss_cover": true}},
    {"gadget": {"gadget_id": 26, "loss_cover": true}},
    {"gadget": {"gadget_id": 27, "loss_cover": true}},
    {"gadget": {"gadget_id": 28, "loss_cover": true}},
    {"gadget": {"gadget_id": 29, "loss_cover": true}},
    {"gadget": {"gadget_id": 30, "loss_cover": true}},
    {"gadget": {"gadget_id": 31, "loss_cover": true}},
    {"gadget": {"gadget_id": 32, "loss_cover": true}},
    {"gadget": {"gadget_id": 33, "loss_cover": true}},
    {"gadget": {"gadget_id": 34, "loss_cover": true}},
    {"gadget": {"gadget_id": 35, "loss_cover": true}},
    {"gadget": {"gadget_id": 36, "loss_cover": true}},
    {"gadget": {"gadget_id": 37, "loss_cover": true}},
    {"gadget": {"gadget_id": 38, "loss_cover": true}},
    {"gadget": {"gadget_id": 39, "loss_cover": true}},
    {"gadget": {"gadget_id": 40, "loss_cover": true}},
    {"gadget": {"gadget_id": 41, "loss_cover": true}},

    {"gadget": {"gadget_id": 215, "loss_cover": true}},
    {"gadget": {"gadget_id": 216, "loss_cover": true}},
    {"gadget": {"gadget_id": 217, "loss_cover": true}},
    {"gadget": {"gadget_id": 218, "loss_cover": true}},
    {"gadget": {"gadget_id": 219, "loss_cover": true}},
    {"gadget": {"gadget_id": 220, "loss_cover": true}},
    {"gadget": {"gadget_id": 221, "loss_cover": true}},
    {"gadget": {"gadget_id": 222, "loss_cover": true}},
    {"gadget": {"gadget_id": 223, "loss_cover": true}},
    {"gadget": {"gadget_id": 224, "loss_cover": true}},

    {"gadget": {"gadget_id": 806, "loss_cover": true}},
    {"gadget": {"gadget_id": 807, "loss_cover": true}},
    {"gadget": {"gadget_id": 808, "loss_cover": true}},
    {"gadget": {"gadget_id": 809, "loss_cover": true}},

    {"gadget": {"gadget_id": 814, "loss_cover": true}},
    {"gadget": {"gadget_id": 815, "loss_cover": true}},
    {"gadget": {"gadget_id": 816, "loss_cover": true}},
    {"gadget": {"gadget_id": 817, "loss_cover": true}},
    {"gadget": {"gadget_id": 818, "loss_cover": true}},
    {"gadget": {"gadget_id": 819, "loss_cover": true}},
    {"gadget": {"gadget_id": 820, "loss_cover": true}},

    {"gadget": {"gadget_id": 835, "loss_cover": true}},
    {"gadget": {"gadget_id": 836, "loss_cover": true}},

    {"gadget": {"gadget_id": 1239, "loss_cover": true}},
    {"gadget": {"gadget_id": 1240, "loss_cover": true}}
  ]
}}'; */

        $url = sprintf(
            '/external/gocompare/feed?gocompare_key=%s',
            static::$container->getParameter('gocompare_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json'
            ),
            json_encode($data)
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(count(GoCompare::$models), count($data['response']), json_encode($data, JSON_PRETTY_PRINT));
    }

    public function testGoCompareDeeplink()
    {
        $email = static::generateEmail('testGoCompareDeeplink', $this);
        $repo = static::$dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNull($user);

        $url = sprintf(
            '/external/gocompare/deeplink'
        );
        $data  = [
            'first_name' => 'foo',
            'surname' => 'bar',
            'email_address' => $email,
            'dob' => '2018-01-01',
            'reference' => static::$phone->getId(),
        ];

        $crawler =  static::$client->request(
            "POST",
            $url,
            $data
        );

        $data = $this->verifyResponse(
            302,
            null,
            null,
            sprintf("%s %s", static::$phone->__toString(), static::$phone->getId())
        );
        $redirectUrl = self::$router->generate('quote_phone', ['id' => static::$phone->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotNull($updatedUser);

        $this->assertEquals('foo', $updatedUser->getFirstName());
        $this->assertEquals('bar', $updatedUser->getLastName());
        $this->assertEquals(new \DateTime('2018-01-01'), $updatedUser->getBirthday());
    }

    public function testGoCompareDeeplinkAddress()
    {
        $email = static::generateEmail('testGoCompareDeeplinkAddress', $this);
        $repo = static::$dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNull($user);

        $url = sprintf(
            '/external/gocompare/deeplink'
        );
        $data  = [
            'first_name' => 'foo',
            'surname' => 'bar',
            'email_address' => $email,
            'dob' => '2018-01-01',
            'house_no' => '123',
            'address_1' => 'foo road',
            'address_2' => 'bar city',
            'postcode' => 'bx11lt',
            'reference' => static::$phone->getId(),
        ];

        $crawler =  static::$client->request(
            "POST",
            $url,
            $data
        );

        $data = $this->verifyResponse(
            302,
            null,
            null,
            sprintf("%s %s", static::$phone->__toString(), static::$phone->getId())
        );
        $redirectUrl = self::$router->generate('quote_phone', ['id' => static::$phone->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotNull($updatedUser);

        $this->assertEquals('foo', $updatedUser->getFirstName());
        $this->assertEquals('bar', $updatedUser->getLastName());
        $this->assertEquals(new \DateTime('2018-01-01'), $updatedUser->getBirthday());
        $this->assertNotNull($updatedUser->getBillingAddress());
        if ($updatedUser->getBillingAddress()) {
            $this->assertEquals('123 foo road', $updatedUser->getBillingAddress()->getLine1());
            $this->assertEquals('bar city', $updatedUser->getBillingAddress()->getCity());
            $this->assertEquals('BX1 1LT', $updatedUser->getBillingAddress()->getPostcode());
        }
    }

    public function testGoCompareDeeplinkInvalidPostcode()
    {
        $email = static::generateEmail('testGoCompareDeeplinkInvalidPostcode', $this);
        $repo = static::$dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNull($user);

        $url = sprintf(
            '/external/gocompare/deeplink'
        );
        $data  = [
            'first_name' => 'foo',
            'surname' => 'bar',
            'email_address' => $email,
            'dob' => '2018-01-01',
            'house_no' => '123',
            'address_1' => 'foo road',
            'address_2' => 'bar city',
            'postcode' => 'se2',
            'reference' => static::$phone->getId(),
        ];

        $crawler =  static::$client->request(
            "POST",
            $url,
            $data
        );

        $data = $this->verifyResponse(
            302,
            null,
            null,
            sprintf("%s %s", static::$phone->__toString(), static::$phone->getId())
        );
        $redirectUrl = self::$router->generate('quote_phone', ['id' => static::$phone->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotNull($updatedUser);

        $this->assertEquals('foo', $updatedUser->getFirstName());
        $this->assertEquals('bar', $updatedUser->getLastName());
        $this->assertEquals(new \DateTime('2018-01-01'), $updatedUser->getBirthday());
        $this->assertNotNull($updatedUser->getBillingAddress());
        if ($updatedUser->getBillingAddress()) {
            $this->assertEquals('123 foo road', $updatedUser->getBillingAddress()->getLine1());
            $this->assertEquals('bar city', $updatedUser->getBillingAddress()->getCity());
            $this->assertNull($updatedUser->getBillingAddress()->getPostcode());
        }
    }

    public function testGoCompareDeeplinkSpace()
    {
        $url = sprintf(
            '/external/gocompare/deeplink'
        );
        $data  = [
            'first_name' => 'foo bar',
            'surname' => 'bar foo',
            'email_address' => static::generateEmail('testGoCompareDeeplinkSpace', $this),
            'dob' => '2018-01-01',
            'reference' => static::$phone->getId(),
        ];

        $crawler =  static::$client->request(
            "POST",
            $url,
            $data
        );

        $data = $this->verifyResponse(
            302,
            null,
            null,
            sprintf("%s %s", static::$phone->__toString(), static::$phone->getId())
        );
        $redirectUrl = self::$router->generate('quote_phone', ['id' => static::$phone->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
    }

    public function testGoCompareDeeplinkUtm()
    {
        $client = self::createClient();

        $url = sprintf(
            '/external/gocompare/deeplink?utm_source=foo'
        );
        $data  = [
            'first_name' => 'foo',
            'surname' => 'bar',
            'email_address' => static::generateEmail('testGoCompareDeeplink', $this),
            'dob' => '2018-01-01',
            'reference' => static::$phone->getId(),
        ];

        $client->request(
            "POST",
            $url,
            $data
        );

        if (!$client->getContainer()) {
            throw new \Exception("missing container");
        }
        $container = $client->getContainer();
        $utm = [];
        /** @var SessionInterface $session */
        $session = $container->get('session');
        if ($session) {
            $utm = unserialize($session->get('utm'));
        }
        $this->assertEquals('foo', $utm['source']);
    }

    public function testGoCompareDeeplinkDuplicateUser()
    {
        $email = self::generateEmail('testGoCompareDeeplinkDuplicateUser', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $url = sprintf(
            '/external/gocompare/deeplink'
        );
        $data  = [
            'first_name' => 'foo',
            'surname' => 'bar',
            'email_address' => $email,
            'dob' => '2018-01-01',
            'reference' => static::$phone->getId(),
        ];

        $crawler =  static::$client->request(
            "POST",
            $url,
            $data
        );

        $data = $this->verifyResponse(
            302,
            null,
            null,
            sprintf("%s %s", static::$phone->__toString(), static::$phone->getId())
        );
        $redirectUrl = self::$router->generate('quote_phone', ['id' => static::$phone->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
    }
}
