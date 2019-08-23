<?php

namespace AppBundle\Form;

use AppBundle\Entity\Address;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddressBookType extends AbstractType
{
    // private $translator;
    // private $doctrine;
    // private $country;

    // public function __construct(TranslatorInterface $translator, ManagerRegistry $doctrine, $country)
    // {
    //     $this->translator = $translator;
    //     $this->doctrine = $doctrine;
    //     $this->country = $country;
    // }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('existingAddress', EntityType::class, [
                'class' => Address::class,
                'choices' => $options['store']->getAddresses(),
                // 'query_builder' => function (EntityRepository $repository) {
                //     return $repository->createQueryBuilder('s')
                //         ->orderBy('s.name', 'ASC');
                // },
                'label' => 'form.address_book.existing_address.label',
                'choice_label' => 'streetAddress',
                'required' => false,
                'mapped' => false,
                // 'disabled' => !$isNew
            ])
            ->add('newAddress', AddressType::class, [
                'label' => 'form.address_book.new_address.label',
                'required' => false,
                'mapped' => false,
                // 'label' => 'form.address.postalCode.label'
            ]);
            ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => Address::class,
            'store' => null
            // 'extended' => false,
            // 'with_telephone' => false,
            // 'with_name' => false
        ));
    }
}
