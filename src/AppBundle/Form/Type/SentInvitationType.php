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

class SentInvitationType extends AbstractType
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
            foreach ($policy->getSentInvitations() as $invitation) {
                $form->add(
                    sprintf('reinvite_%s', $invitation->getId()),
                    SubmitType::class,
                    ['label' => 'Resend', 'attr' => [
                        'class' => 'btn btn-primary',
                        'disabled' => !$invitation->canReinvite()
                    ]]
                );
                $form->add(
                    sprintf('cancel_%s', $invitation->getId()),
                    SubmitType::class,
                    ['label' => 'Cancel', 'attr' => ['class' => 'btn btn-danger']]
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
