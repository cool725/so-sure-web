<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolType extends AbstractType
{

    /**
     * @var boolean
     */
    private $required;
 
    /**
     * @param boolean $required
     */
    public function __construct($required)
    {
        $this->required = $required;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', EmailType::class, ['disabled' => true])
            ->add('name', TextType::class, ['disabled' => true])
            ->add('phone', TextType::class)
            ->add('when', DateType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('time', TextType::class)
            ->add('where', TextType::class)
            ->add('timeToReach', TextType::class)
            ->add('signature', TextType::class)
            ->add('type', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'My phone is...',
                'choices' => [
                    'Lost' => Claim::TYPE_LOSS,
                    'Stolen' => Claim::TYPE_THEFT,
                    'Damaged or not working' => Claim::TYPE_DAMAGE,
                ],
            ])
            ->add('network', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'My network operator is ...',
                'choices' => Claim::$networks,
            ])
            ->add('message', TextareaType::class)
            ->add('submit', SubmitType::class)
        ;


        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();

            $policies = array();
            $userPolicies = $data->getUser()->getValidPoliciesWithoutOpenedClaim(true);
            foreach ($userPolicies as $policy) {
                $policies[$policy->getPolicyNumber()] = $policy->getId();
            }

            $form->add('policyNumber', ChoiceType::class, [
                'required' => true,
                'expanded' => false,
                'multiple' => false,
                'choices' => $policies
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnol',
        ));
    }
}
