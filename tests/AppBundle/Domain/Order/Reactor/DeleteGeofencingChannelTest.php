<?php

namespace Tests\AppBundle\Domain\Order\Reactor;

use AppBundle\Domain\Order\Event;
use AppBundle\Domain\Order\Reactor\DeleteGeofencingChannel;
use AppBundle\Entity\Address;
use AppBundle\Entity\Base\GeoCoordinates;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\Sylius\Order;
use AppBundle\Entity\Task;
use AppBundle\Sylius\Order\OrderInterface;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;
use Prophecy\Argument;
use Psr\Log\NullLogger;

class DeleteGeofencingChannelTest extends TestCase
{
    private $reactor;

    public function setUp(): void
    {
        $this->tile38 = $this->prophesize(Redis::class);

        $this->reactor = new DeleteGeofencingChannel(
            $this->tile38->reveal(),
            'coopcycle',
            new NullLogger()
        );
    }

    public function testDeletesChannel()
    {
        $dropoff = $this->prophesize(Task::class);
        $dropoff
            ->isDoorstep()
            ->willReturn(true);
        $dropoff
            ->getId()
            ->willReturn(42);

        $delivery = $this->prophesize(Delivery::class);
        $delivery
            ->getDropoff()
            ->willReturn($dropoff->reveal());

        $order = $this->prophesize(Order::class);
        $order
            ->getDelivery()
            ->willReturn($delivery->reveal());

        $this->tile38
            ->executeRaw([
                'DELCHAN',
                'coopcycle:dropoff:42',
            ])
            ->shouldBeCalled();

        call_user_func_array($this->reactor, [ new Event\OrderDropped($order->reveal()) ]);
    }
}
