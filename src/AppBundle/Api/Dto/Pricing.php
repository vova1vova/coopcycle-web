<?php

namespace AppBundle\Api\Dto;

use AppBundle\Action\Delivery\Pricing as PricingController;
use AppBundle\Api\Dto\PricingInput;
use AppBundle\Api\Dto\PricingOutput;
use ApiPlatform\Core\Annotation\ApiResource;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ApiResource(
 *   collectionOperations={
 *     "calc_price"={
 *       "method"="POST",
 *       "path"="/pricing/deliveries",
 *       "controller"=PricingController::class,
 *       "input"=PricingInput::class,
 *       "output"=PricingOutput::class,
 *       "write"=false
 *     },
 *   },
 *   itemOperations={},
 * )
 */
final class Pricing
{
    public $price;
}
