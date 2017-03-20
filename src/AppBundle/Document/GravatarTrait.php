<?php

namespace AppBundle\Document;

trait GravatarTrait
{
    public function gravatarImage($email, $size)
    {
        return sprintf(
            'https://www.gravatar.com/avatar/%s?d=404&s=%d',
            md5(strtolower(trim($email))),
            $size
        );
    }

    public function gravatarImageFallback($email, $size, $fallback)
    {
        return sprintf(
            'https://www.gravatar.com/avatar/%s?s=%d&d=%s',
            md5(strtolower(trim($email))),
            $size,
            urlencode($fallback)
        );
    }
}
