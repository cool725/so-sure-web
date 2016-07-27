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
}
