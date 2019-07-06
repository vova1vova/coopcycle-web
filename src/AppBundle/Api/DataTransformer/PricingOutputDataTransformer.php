<?php

namespace AppBundle\Api\DataTransformer;

use ApiPlatform\Core\DataTransformer\DataTransformerInterface;
use AppBundle\Api\Dto\Pricing;
use AppBundle\Api\Dto\PricingOutput;
use AppBundle\Entity\Delivery;

final class PricingOutputDataTransformer implements DataTransformerInterface
{
    /**
     * {@inheritdoc}
     */
    public function transform($data, string $to, array $context = [])
    {
        $output = new PricingOutput();
        $output->price = $data->price;

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransformation($data, string $to, array $context = []): bool
    {
        return PricingOutput::class === $to && $data instanceof Pricing;
    }
}
