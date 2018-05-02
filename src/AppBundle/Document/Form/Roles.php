<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Roles
{
    protected $roles;
    protected $rolesConfig;

    public function getRoles()
    {
        return $this->roles;
    }

    public function setRoles($roles)
    {
        $this->roles = array();

        if (is_array($roles)) {
            foreach ($roles as $role) {
                $this->roles[] = $role;
            }
        } else {
            $this->roles[] = $roles;
        }
    }
}
