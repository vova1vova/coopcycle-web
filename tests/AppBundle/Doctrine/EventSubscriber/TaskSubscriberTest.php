<?php

namespace Tests\AppBundle\Doctrine\EventSubscriber;

use AppBundle\Doctrine\EventSubscriber\TaskSubscriber;
use AppBundle\Domain\EventStore;
use AppBundle\Domain\Task\Event\TaskAssigned;
use AppBundle\Domain\Task\Event\TaskCreated;
use AppBundle\Entity\ApiUser;
use AppBundle\Entity\Task;
use AppBundle\Entity\TaskList;
use AppBundle\Message\PushNotification;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Prophecy\Argument;
use SimpleBus\Message\Bus\MessageBus;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Avoid error "Returning by reference not supported" with Prophecy
class UnitOfWork
{
    public function getScheduledEntityInsertions()
    {}

    public function getScheduledEntityUpdates()
    {}

    public function getEntityChangeSet($entity)
    {}

    public function computeChangeSets()
    {}
}

class TaskSubscriberTest extends TestCase
{
    public function setUp(): void
    {
        $this->eventBus = $this->prophesize(MessageBus::class);

        $this->taskListRepository = $this->prophesize(ObjectRepository::class);

        $this->entityManager = $this->prophesize(EntityManagerInterface::class);

        $this->messageBus = $this->prophesize(MessageBusInterface::class);

        $this->messageBus
            ->dispatch(Argument::type(PushNotification::class))
            ->will(function ($args) {
                return new Envelope($args[0]);
            });

        $this->entityManager
            ->getRepository(TaskList::class)
            ->willReturn($this->taskListRepository->reveal());

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $requestStack = $this->prophesize(RequestStack::class);

        $eventStore = new EventStore($tokenStorage->reveal(), $requestStack->reveal());

        $this->subscriber = new TaskSubscriber(
            $this->eventBus->reveal(),
            $eventStore,
            $this->messageBus->reveal(),
            new NullLogger()
        );
    }

    public function testOnFlushWithNewTask()
    {
        $unitOfWork = $this->prophesize(UnitOfWork::class);

        $task = new Task();

        $unitOfWork
            ->getScheduledEntityInsertions()
            ->willReturn([
                $task
            ]);
        $unitOfWork
            ->getScheduledEntityUpdates()
            ->willReturn([]);
        $unitOfWork
            ->getEntityChangeSet($task)
            ->willReturn([]);
        $unitOfWork
            ->computeChangeSets()
            ->shouldBeCalledTimes(1);

        $this->entityManager->getUnitOfWork()->willReturn($unitOfWork->reveal());

        $this->subscriber->onFlush(
            new OnFlushEventArgs($this->entityManager->reveal())
        );
        $this->subscriber->postFlush(
            new PostFlushEventArgs($this->entityManager->reveal())
        );

        $this->assertCount(1, $task->getEvents());
        $this->eventBus
            ->handle(Argument::type(TaskCreated::class))
            ->shouldHaveBeenCalledTimes(1);

        // Make sure it can be called several
        // times during the same request cycle

        $unitOfWork
            ->getScheduledEntityInsertions()
            ->willReturn([]);
        $unitOfWork
            ->getScheduledEntityUpdates()
            ->willReturn([]);
        $unitOfWork
            ->getEntityChangeSet($task)
            ->willReturn([]);

        $this->subscriber->onFlush(
            new OnFlushEventArgs($this->entityManager->reveal())
        );
        $this->subscriber->postFlush(
            new PostFlushEventArgs($this->entityManager->reveal())
        );
    }

    public function testOnFlushWithAssignedTask()
    {
        $unitOfWork = $this->prophesize(UnitOfWork::class);

        $user = new ApiUser();
        $user->setUsername('bob');

        $task = new Task();
        $task->setDoneBefore(new \DateTime('2019-11-21 19:00:00'));

        $unitOfWork
            ->getScheduledEntityInsertions()
            ->willReturn([]);
        $unitOfWork
            ->getScheduledEntityUpdates()
            ->willReturn([
                $task
            ]);
        $unitOfWork
            ->getEntityChangeSet($task)
            ->willReturn([
                'assignedTo' => [ null, $user ]
            ]);
        $unitOfWork
            ->computeChangeSets()
            ->shouldBeCalledTimes(1);

        $this->entityManager->getUnitOfWork()->willReturn($unitOfWork->reveal());

        $this->entityManager
            ->persist(Argument::type(TaskList::class))
            ->shouldBeCalled();

        $this->subscriber->onFlush(
            new OnFlushEventArgs($this->entityManager->reveal())
        );
        $this->subscriber->postFlush(
            new PostFlushEventArgs($this->entityManager->reveal())
        );

        $this
            ->messageBus
            ->dispatch(new PushNotification(
                'Tasks for 2019-11-21 changed!',
                [ 'bob' ],
                [
                    'event' => [
                        'name' => 'tasks:changed',
                        'data' => ['date' => '2019-11-21']
                    ]
                ]
            ))
            ->shouldHaveBeenCalled();

        $this->assertCount(0, $task->getEvents());
        $this->eventBus
            ->handle(Argument::type(TaskCreated::class))
            ->shouldNotHaveBeenCalled();
        $this->eventBus
            ->handle(Argument::type(TaskAssigned::class))
            ->shouldHaveBeenCalledTimes(1);

        // Make sure it can be called several
        // times during the same request cycle

        $unitOfWork
            ->getScheduledEntityInsertions()
            ->willReturn([]);
        $unitOfWork
            ->getScheduledEntityUpdates()
            ->willReturn([]);
        $unitOfWork
            ->getEntityChangeSet($task)
            ->willReturn([]);

        $this->subscriber->onFlush(
            new OnFlushEventArgs($this->entityManager->reveal())
        );
        $this->subscriber->postFlush(
            new PostFlushEventArgs($this->entityManager->reveal())
        );
    }

    public function testOnFlushWithSeveralAssignedTasksToSameUser()
    {
        $unitOfWork = $this->prophesize(UnitOfWork::class);

        $user = new ApiUser();
        $user->setUsername('bob');

        $task1 = new Task();
        $task1->setDoneBefore(new \DateTime('2019-11-21 19:00:00'));

        $task2 = new Task();
        $task2->setDoneBefore(new \DateTime('2019-11-21 21:00:00'));

        $unitOfWork
            ->getScheduledEntityInsertions()
            ->willReturn([]);
        $unitOfWork
            ->getScheduledEntityUpdates()
            ->willReturn([
                $task1,
                $task2,
            ]);
        $unitOfWork
            ->getEntityChangeSet($task1)
            ->willReturn([
                'assignedTo' => [ null, $user ]
            ]);
        $unitOfWork
            ->getEntityChangeSet($task2)
            ->willReturn([
                'assignedTo' => [ null, $user ]
            ]);
        $unitOfWork
            ->computeChangeSets()
            ->shouldBeCalledTimes(2);

        $this->entityManager->getUnitOfWork()->willReturn($unitOfWork->reveal());

        $this->entityManager
            ->persist(Argument::type(TaskList::class))
            ->shouldBeCalled();

        $this->subscriber->onFlush(
            new OnFlushEventArgs($this->entityManager->reveal())
        );
        $this->subscriber->postFlush(
            new PostFlushEventArgs($this->entityManager->reveal())
        );

        $this
            ->messageBus
            ->dispatch(new PushNotification(
                'Tasks for 2019-11-21 changed!',
                [ 'bob' ],
                [
                    'event' => [
                        'name' => 'tasks:changed',
                        'data' => ['date' => '2019-11-21']
                    ]
                ]
            ))
            ->shouldHaveBeenCalledTimes(1);

        $this->assertCount(0, $task1->getEvents());
        $this->assertCount(0, $task2->getEvents());

        $this->eventBus
            ->handle(Argument::type(TaskCreated::class))
            ->shouldNotHaveBeenCalled();
    }
}
