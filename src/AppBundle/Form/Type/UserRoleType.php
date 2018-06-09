<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Policy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class UserRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('roles', ChoiceType::class, array(
            'choices'   => [
                User::ROLE_CLAIMS => User::ROLE_CLAIMS,
                User::ROLE_EMPLOYEE => User::ROLE_EMPLOYEE,
                User::ROLE_CUSTOMER_SERVICES => User::ROLE_CUSTOMER_SERVICES,
                User::ROLE_ADMIN => User::ROLE_ADMIN,
            ],
            'required'  => true,
            'multiple'  => true
        ))
        ->add('save', SubmitType::class) ;

    }
    

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            
            'data_class' => 'AppBundle\Document\Form\Roles',
        ));
    }
}
