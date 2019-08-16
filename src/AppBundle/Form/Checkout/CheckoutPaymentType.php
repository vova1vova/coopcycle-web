<?php

namespace AppBundle\Form\Checkout;

use AppBundle\Form\StripePaymentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class CheckoutPaymentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('cardholderName', TextType::class, [
                'mapped' => false,
                'label' => 'form.checkout_payment.cardholder_name.label'
            ])
            ->add('stripePayment', StripePaymentType::class, [
                'mapped' => false,
            ]);
    }
}
