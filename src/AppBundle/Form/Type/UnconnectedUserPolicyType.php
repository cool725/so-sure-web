<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\PhonePolicy;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class UnconnectedUserPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        /*
        $builder
            ->add('submit', SubmitType::class);
*/
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $policy = $event->getData();
            $form = $event->getForm();
            foreach ($policy->getUnconnectedUserPolicies() as $unconnectedPolicy) {
                $form->add(
                    sprintf('connect_%s', $unconnectedPolicy->getId()),
                    SubmitType::class,
                    ['label' => 'Connect', 'attr' => ['class' => 'btn btn-primary']]
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
