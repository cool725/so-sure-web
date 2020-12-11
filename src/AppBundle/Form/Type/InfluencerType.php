<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Reward;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

/**
 * Makes a form which lets you make a reward.
 */
class InfluencerType extends AbstractType
{

    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    /**
     * @Override
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('firstName', TextType::class, ['required' => true])
            ->add('lastName', TextType::class, ['required' => true])
            ->add('email', EmailType::class, ['required' => true])
            ->add('gender', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Select...',
                'choices' => array(
                    'Male' => 'Male',
                    'Female' => 'Female',
                    'Other' => 'Unknown'
                ),
            ])
            ->add('organisation', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Select...',
                'choices' => array(
                    'Campus Industries' => 'Campus Industries'
                ),
            ])
            ->add('next', SubmitType::class);
    }

    /**
     * @Override
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => 'AppBundle\Document\Form\CreateInfluencer',
            'csrf_protection'   => true
        ]);
    }
}
