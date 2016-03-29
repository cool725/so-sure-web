<?php

namespace AppBundle\Tests;

trait UserClassTrait
{
    public static function createUser($userManager, $email, $password)
    {
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $userManager->updateUser($user, true);

        return $user;
    }
}
