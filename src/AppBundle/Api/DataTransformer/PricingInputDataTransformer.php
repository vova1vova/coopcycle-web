<?php

namespace AppBundle\Api\DataTransformer;

use ApiPlatform\Core\DataTransformer\DataTransformerInterface;
use AppBundle\Api\Dto\PricingInput;
use AppBundle\Entity\Delivery;
use AppBundle\Api\Dto\Pricing;
use AppBundle\Serializer\DeliveryNormalizer;
use AppBundle\Service\RoutingInterface;
use AppBundle\Service\DeliveryManager;
use ApiPlatform\Core\Api\IriConverterInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PricingInputDataTransformer implements DataTransformerInterface
{
    public function __construct(
        DeliveryNormalizer $deliveryNormalizer,
        RoutingInterface $routing,
        DeliveryManager $deliveryManager,
        IriConverterInterface $iriConverter)
    {
        $this->deliveryNormalizer = $deliveryNormalizer;
        $this->routing = $routing;
        $this->deliveryManager = $deliveryManager;
        $this->iriConverter = $iriConverter;
    }

    /**
     * {@inheritdoc}
     */
    public function transform($data, string $to, array $context = [])
    {
        $store = $this->iriConverter->getItemFromIri($data->store);

        $deliveryData = [
            'pickup' => $data->pickup,
            'dropoff' => $data->dropoff,
        ];
        $delivery = $this->deliveryNormalizer->denormalize($deliveryData, Delivery::class);

        $osrmData = $this->routing->getRawResponse(
            $delivery->getPickup()->getAddress()->getGeo(),
            $delivery->getDropoff()->getAddress()->getGeo()
        );

        $distance = $osrmData['routes'][0]['distance'];

        $delivery->setDistance(ceil($distance));
        $delivery->setWeight($data->weight ?? null);

        $price = $this->deliveryManager->getPrice($delivery, $store->getPricingRuleSet());

        if (null === $price) {
            throw new BadRequestHttpException('Price could not be calculated');
        }

        $pricing = new Pricing();
        $pricing->price = $price;

        return $pricing;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTransformation($data, string $to, array $context = []): bool
    {
        if ($data instanceof Pricing) {
          return false;
        }

        return Pricing::class === $to && null !== ($context['input']['class'] ?? null);
    }
}
