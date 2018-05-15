<?php

namespace AppBundle\Form;

use AppBundle\Entity\Sylius\ProductOption;
use Ramsey\Uuid\Uuid;
use Sylius\Bundle\TaxationBundle\Form\Type\TaxCategoryChoiceType;
use Sylius\Component\Product\Factory\ProductVariantFactoryInterface;
use Sylius\Component\Product\Generator\ProductVariantGeneratorInterface;
use Sylius\Component\Product\Model\Product;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    private $variantGenerator;
    private $variantFactory;
    private $variantResolver;

    public function __construct(
        ProductVariantGeneratorInterface $variantGenerator,
        ProductVariantFactoryInterface $variantFactory,
        ProductVariantResolverInterface $variantResolver)
    {
        $this->variantGenerator = $variantGenerator;
        $this->variantFactory = $variantFactory;
        $this->variantResolver = $variantResolver;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'form.product.name.label'
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'form.product.description.label'
            ])
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'form.product.enabled.label',
            ]);

        // While price & tax category are defined in ProductVariant,
        // we display the fields at the Product level
        // For now, all variants share the same values
        $builder
            ->add('price', MoneyType::class, [
                'mapped' => false,
                'divisor' => 100,
                'label' => 'form.product.price.label'
            ])
            ->add('taxCategory', TaxCategoryChoiceType::class, [
                'mapped' => false,
                'label' => 'form.product.taxCategory.label'
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {

            $form = $event->getForm();
            $product = $event->getData();

            $form->add('options', EntityType::class, [
                'class' => ProductOption::class,
                'choices' => $product->getRestaurant()->getProductOptions(),
                'expanded' => true,
                'multiple' => true,
            ]);

            if (null !== $product->getId()) {

                if ($product->hasOptions()) {
                    $this->variantGenerator->generate($product);
                }

                $variant = $this->variantResolver->getVariant($product);

                // To keep things simple, all variants have the same price & tax category
                $form->get('price')->setData($variant->getPrice());
                $form->get('taxCategory')->setData($variant->getTaxCategory());

                foreach ($product->getVariants() as $variant) {
                    $variant->setName($product->getName());
                }
            }

            $form->add('variants', CollectionType::class, [
                'entry_type' => ProductVariantType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'prototype' => false,
                'label' => false,
            ]);

        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {

            $form = $event->getForm();
            $product = $event->getData();

            if (null === $product->getId()) {

                $uuid = Uuid::uuid4()->toString();

                $product->setCode($uuid);
                $product->setSlug($uuid);

                if ($product->hasOptions()) {
                    $this->variantGenerator->generate($product);
                } else {
                    $variant = $this->variantFactory->createForProduct($product);
                    $product->addVariant($variant);
                }

                $price = $form->get('price')->getData();
                $taxCategory = $form->get('taxCategory')->getData();

                foreach ($product->getVariants() as $variant) {
                    $variant->setName($product->getName());
                    $variant->setCode(Uuid::uuid4()->toString());
                    $variant->setPrice($price);
                    $variant->setTaxCategory($taxCategory);
                }

            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => Product::class,
        ));
    }
}
