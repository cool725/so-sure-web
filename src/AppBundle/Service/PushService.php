<?php
namespace AppBundle\Service;

use AppBundle\Document\User;
use Aws\Sns\SnsClient;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\ClientUrl;
use AppBundle\Document\Policy;

class PushService
{
    const DISPLAY_POPUP = 'popup';
    const DISPLAY_NONE = 'none';
    const DISPLAY_INLINE = 'inline';

    const MESSAGE_GENERAL = 'general';

    // sent to inviter
    const MESSAGE_CONNECTED = 'connected';

    // sent to invitee
    const MESSAGE_INVITATION = 'invitation';

    // these are currently mixpanel events
    const MESSAGE_RATEMYAPP = 'ratemyapp';
    const MESSAGE_PROMO = 'promo';
    const MESSAGE_MULTIPAY = 'multipay';

    const PSEUDO_MESSAGE_PICSURE = 'picsure';

    /** @var LoggerInterface */
    protected $logger;

    /** @var SnsClient  */
    protected $sns;

    /** @var \Domnikl\Statsd\Client */
    protected $statsd;

    /**
     * @param LoggerInterface        $logger
     * @param SnsClient              $sns
     * @param \Domnikl\Statsd\Client $statsd
     */
    public function __construct(
        LoggerInterface $logger,
        SnsClient $sns,
        \Domnikl\Statsd\Client $statsd
    ) {
        $this->logger = $logger;
        $this->sns = $sns;
        $this->statsd = $statsd;
    }

    public function sendToUser(
        $messageType,
        User $user,
        $message,
        $badge = null,
        $messageData = null,
        Policy $policy = null
    ) {
        $this->logger->debug(sprintf('Push triggered to user id: %s %s', $user->getId(), $message));
        if (!$user->getSnsEndpoint() || mb_strlen(trim($user->getSnsEndpoint())) == 0) {
            $this->logger->debug(sprintf('Push skipped (no endpoint)'));

            return;
        }

        return $this->send($messageType, $user->getSnsEndpoint(), $message, $badge, $messageData, $policy);
    }

    public function send(
        $messageType,
        $arn,
        $message,
        $badge = null,
        $messageData = null,
        Policy $policy = null
    ) {
        $this->logger->debug(sprintf('Push triggered to %s %s', $arn, $message));
        try {
            $apns = $this->generateAPNSMessage($messageType, $message, $badge, $messageData, null, $policy);
            $gcm = $this->generateGCMMessage($messageType, $message, $messageData, $policy);
            $this->sns->publish([
               'TargetArn' => $arn,
               'MessageStructure' => 'json',
                'Message' => json_encode([
                    'APNS' => json_encode($apns),
                    'APNS_SANDBOX' => json_encode($apns),
                    'GCM' => json_encode($gcm),
                ])
            ]);
        } catch (\Exception $e) {
            $this->logger->info(sprintf('Failed to push %s to %s', $message, $arn));
            $this->statsd->increment('aws.sns.failure');

            return false;
        }
    }

    public function getUri($messageType, Policy $policy = null)
    {
        if ($messageType == self::MESSAGE_GENERAL) {
            return null;
        } elseif ($messageType == self::MESSAGE_CONNECTED) {
            return ClientUrl::getUrlWithQuerystring(ClientUrl::POT, $policy);
        } elseif ($messageType == self::MESSAGE_INVITATION) {
            return ClientUrl::getUrlWithQuerystring(ClientUrl::POT, $policy);
        } elseif ($messageType == self::PSEUDO_MESSAGE_PICSURE) {
            return ClientUrl::getUrlWithQuerystring(ClientUrl::PICSURE, $policy);
        } else {
            return null;
        }
    }

    public function getDisplay($messageType)
    {
        if ($messageType == self::MESSAGE_GENERAL) {
            return self::DISPLAY_POPUP;
        } elseif ($messageType == self::MESSAGE_CONNECTED) {
            return self::DISPLAY_POPUP;
        } elseif ($messageType == self::MESSAGE_INVITATION) {
            return self::DISPLAY_POPUP;
        } elseif ($messageType == self::MESSAGE_MULTIPAY) {
            return self::DISPLAY_POPUP;
        } else {
            return null;
        }
    }

    public function getRefresh($messageType)
    {
        if ($messageType == self::MESSAGE_GENERAL) {
            return null;
        } elseif ($messageType == self::MESSAGE_CONNECTED) {
            return true;
        } elseif ($messageType == self::MESSAGE_INVITATION) {
            return true;
        } elseif ($messageType == self::MESSAGE_MULTIPAY) {
            return true;
        } elseif ($messageType == self::PSEUDO_MESSAGE_PICSURE) {
            return true;
        } else {
            return null;
        }
    }

    public function getActualMessageType($messageType)
    {
        if ($messageType == self::PSEUDO_MESSAGE_PICSURE) {
            return self::MESSAGE_GENERAL;
        }

        return $messageType;
    }

    /**
     * @see https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
     */
    public function generateGCMMessage($messageType, $message, $messageData = null, Policy $policy = null)
    {
        $gcm['data']['message'] = $message;

        $gcm['data'] = array_merge($gcm['data'], $this->getCustomData($messageType, $messageData, $policy));

        return $gcm;
    }

    /**
     * @codingStandardsIgnoreStart
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html
     * @codingStandardsIgnoreEnd
     */
    public function generateAPNSMessage(
        $messageType,
        $message,
        $badge = null,
        $messageData = null,
        $newContent = null,
        Policy $policy = null
    ) {
        if ($badge && $newContent) {
            throw new \Exception('Silent notifications can not contain badge updates');
        }

        $apns['aps']['alert'] = $message;
        $apns['aps']['category'] = $this->getActualMessageType($messageType);
        if ($badge) {
            $apns['aps']['badge'] = $badge;
        }
        if ($newContent) {
            $apns['aps']['content-available'] = 1;
        }

        // custom data
        $apns = array_merge($apns, $this->getCustomData($messageType, $messageData, $policy));

        return $apns;
    }

    public function getCustomData($messageType, $messageData = null, Policy $policy = null)
    {
        $data = [];
        $data['ss']['message_type'] = $this->getActualMessageType($messageType);
        if ($messageData) {
            $data['ss']['data'][$messageType] = $messageData;
        }
        $uri = $this->getUri($messageType, $policy);
        if ($uri) {
            $data['ss']['uri'] = $uri;
        }
        $display = $this->getDisplay($messageType);
        if ($display) {
            $data['ss']['display'] = $display;
        }
        $refresh = $this->getRefresh($messageType);
        if ($refresh) {
            $data['ss']['refresh'] = $refresh;
        }

        // Depreciated field, but keep as alert to always display message
        $data['type'] = 'alert';

        return $data;
    }
}
