<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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
class RewardEditType extends AbstractType
{
    protected $dm;

    protected $typesOptions;

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
        $types = $this->dm->createQueryBuilder(Reward::class)
            ->distinct('type')
            ->getQuery()
            ->execute();

        foreach ($types as $type) {
            $this->typesOptions[$type]=$type;
        }

        $builder
            ->add('code', TextType::class, ['required' => false ,'mapped' => false])
            ->add('type', ChoiceType::class, [
                  'required' => true,
                  'choices' => $this->typesOptions
             ])
            ->add('target', TextareaType::class, ['required' => false])
            ->add('defaultValue', TextType::class, ['required' => true])
            ->add('expiryDate', DateType::class, [
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => ['year' => 'YYYY', 'month' => 'MM', 'day' => 'DD'],
                  'required' => true
            ])
            ->add('policyAgeMin', TextType::class, ['required' => false])
            ->add('policyAgeMax', TextType::class, ['required' => false])
            ->add('usageLimit', TextType::class, ['required' => false])
            ->add('hasNotClaimed', CheckboxType::class, ['required' => false])
            ->add('hasRenewed', CheckboxType::class, ['required' => false])
            ->add('hasCancelled', CheckboxType::class, ['required' => false])
            ->add('isFirst', CheckboxType::class, ['required' => false])
            ->add('isSignUpBonus', CheckboxType::class, ['required' => false])
            ->add('isConnectionBonus', CheckboxType::class, ['required' => false])
            ->add('termsAndConditions', TextareaType::class, ['required' => false])
            ->add('submit', SubmitType::class);

            $builder->get('type')->addEventListener(
                FormEvents::PRE_SUBMIT,
                function (FormEvent $event) {
                    $form = $event->getForm()->getParent();
                    if ($form instanceof FormInterface) {
                        $this->setTypeOptions($form, $event->getData());
                    }
                }
            );
    }

    /**
     * @Override
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(['data_class' => 'AppBundle\Document\Reward']);
    }

    /**
     * Set type options to avoid invalid value
     * @param FormInterface $form
     */
    private function setTypeOptions(FormInterface $form, ?string $type)
    {
        if (!array_key_exists($type, $this->typesOptions)) {
            $this->typesOptions[$type] = $type;
            $form->remove('type')
                 ->add('type', ChoiceType::class, [
                  'required' => true,
                  'choices' => $this->typesOptions,
                  'empty_data'  => $type,
                  ]);
        }
    }
}
