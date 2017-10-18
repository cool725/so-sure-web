<?php

namespace AppBundle\Security;

use HWI\Bundle\OAuthBundle\OAuth\Response\AbstractUserResponse;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;

class AccountKitUserResponse extends AbstractUserResponse implements UserResponseInterface
{
    /**
     * {@inheritdoc}
     */
    public function setResponse($response)
    {
        if (is_array($response)) {
            $this->response = $response;
        } else {
            // First check that response exists, due too bug: https://bugs.php.net/bug.php?id=54484
            if (!$response) {
                $this->response = array();
            } else {
                $this->response = json_decode($response, true);

                if (JSON_ERROR_NONE !== json_last_error()) {
                    throw new AuthenticationException('Response is not a valid JSON code.');
                }
            }
        }   
    }
}
