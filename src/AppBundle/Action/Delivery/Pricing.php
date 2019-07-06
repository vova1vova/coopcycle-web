<?php

namespace AppBundle\Action\Delivery;

use AppBundle\Api\Dto\PricingOutput;
use AppBundle\Api\Dto\Pricing as PricingDto;
use AppBundle\Entity\Delivery;
use Symfony\Component\HttpFoundation\Request;

class Pricing
{
    public function __invoke($data)
    {
        $output = new PricingOutput();
        $output->price = $data->price;

        return $output;
    }
}
