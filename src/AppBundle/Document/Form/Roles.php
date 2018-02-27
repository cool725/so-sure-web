<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Roles
{
    /**
     * @var string
     * @Assert\NotBlank()
     */
    protected $roles;
    protected $rolesConfig;

    public function __construct()
    {
        // GET ROLES PARAMETER FROM CONFIG.YML. TODO
        // SETTING ROLES PARAMETER
        $this->rolesConfig = array( 'ROLE_ADMIN' => 'ROLE_ADMIN',
                                    'ROLE_CLAIM' => 'ROLE_CLAIM',
                                    'ROLE_EMPLOYEE' => 'ROLE_EMPLOYEE');
    }

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
