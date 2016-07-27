<?php

namespace AppBundle\Document;

trait GravatarTrait
{
    public function fallbackImage($letter = null)
    {
        if ($letter) {
            return sprintf('https://cdn.so-sure.com/images/profile/%s.png', strtolower($letter));
        } else {
            return sprintf('https://cdn.so-sure.com/images/profile/unknown.png');
        }
    }

    public function gravatarImage($email, $size, $letter = null)
    {
        return sprintf(
            'https://www.gravatar.com/avatar/%s?d=%s&s=%d',
            md5(strtolower(trim($email))),
            urlencode($this->fallbackImage($letter)),
            $size
        );
    }
}
