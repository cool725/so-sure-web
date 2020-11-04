<?php

namespace AppBundle\Document;

trait EmailTrait
{
    /**
     * Check if the email is a gmail account
     * @param string $email
     * @return boolean true if gmail email, false otherwise
     */
    public function isGmail($email)
    {
        list($user, $domain) = explode('@', $email);

        if ($domain == 'gmail.com' || $domain == 'googlemail.com') {
            return true;
        }

        return false;
    }
}
