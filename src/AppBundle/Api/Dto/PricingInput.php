<?php

namespace AppBundle\Api\Dto;

use Symfony\Component\Serializer\Annotation\Groups;

final class PricingInput
{
	public $store;

	public $weight;

	public $pickup;

	public $dropoff;
}
