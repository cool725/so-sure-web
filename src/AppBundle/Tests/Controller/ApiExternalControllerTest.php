<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiExternalControllerTest extends BaseControllerTest
{
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
        $this->assertTrue(strlen($data['jwt']) > 20);
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
        $optouts = $repo->findBy(['email' => 'patrick@so-sure.com']);
        $this->assertGreaterThan(1, count($optouts));
        foreach ($optouts as $optout) {
            $this->assertTrue(in_array($optout->getCategory(), [
                EmailOptOut::OPTOUT_CAT_AQUIRE,
                EmailOptOut::OPTOUT_CAT_RETAIN
            ]));
        }
    }

    public function testMixpanelDelete()
    {
        $data = '[
   {
      "$distinct_id":"13b20239a29335",
      "$properties":{
         "$region":"California",
         "$email":"harry.q.bovik@andrew.cmu.edu",
         "$last_name":"Bovik",
         "$created":"2012-11-20T15:26:16",
         "$country_code":"US",
         "$first_name":"Harry",
         "Referring Domain":"news.ycombinator.com",
         "$city":"Los Angeles",
         "Last Seen":"2012-11-20T15:26:17",
         "Referring URL":"http://news.ycombinator.com/",
         "$last_seen":"2012-11-20T15:26:19"
      }
   },
   {
      "$distinct_id":"13a00df8730412",
      "$properties":{
         "$region":"California",
         "$email":"anna.lytics@mixpanel.com",
         "$last_name":"Lytics",
         "$created":"2012-11-20T15:25:38",
         "$country_code":"US",
         "$first_name":"Anna",
         "Referring Domain":"www.quora.com",
         "$city":"Mountain View",
         "Last Seen":"2012-11-20T15:25:39",
         "Referring URL":"http://www.quora.com/What-...",
         "$last_seen":"2012-11-20T15:25:42"
      }
   }
]';
        $this->clearRateLimit();
        $url = sprintf(
            '/external/mixpanel/delete?mixpanel_webhook_key=%s&debug=true',
            static::$container->getParameter('mixpanel_webhook_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['users' => json_encode($data)]
        );

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
}
