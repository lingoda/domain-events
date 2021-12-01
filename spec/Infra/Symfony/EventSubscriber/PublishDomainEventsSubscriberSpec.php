<?php

declare(strict_types = 1);

namespace spec\Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber;

use Lingoda\DomainEventsBundle\Domain\Model\EventPublisher;
use Lingoda\DomainEventsBundle\Infra\Symfony\EventSubscriber\PublishDomainEventsSubscriber;
use PhpSpec\ObjectBehavior;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

class PublishDomainEventsSubscriberSpec extends ObjectBehavior
{
    function let(
        EventPublisher $eventPublisher
    ) {
        $this->beConstructedWith($eventPublisher, true);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(PublishDomainEventsSubscriber::class);
        $this::getSubscribedEvents()->shouldIterateLike([
            KernelEvents::TERMINATE => 'publishEventsFromHttp',
            ConsoleEvents::TERMINATE => 'publishEventsFromConsole',
            WorkerMessageHandledEvent::class => 'publishEventsFromWorker',
        ]);
    }

    function it_publish_event_on_http_termination(
        EventPublisher $eventPublisher,
        HttpKernelInterface $kernel,
        Request $request,
        Response $response
    ) {
        $eventPublisher->publishDomainEvents()->shouldBeCalledOnce();

        $this->publishEventsFromHttp(new TerminateEvent(
            $kernel->getWrappedObject(),
            $request->getWrappedObject(),
            $response->getWrappedObject()
        ));
    }

    function it_publish_event_on_console_termination(
        EventPublisher $eventPublisher,
        Command $command,
        InputInterface $input,
        OutputInterface $output
    ) {
        $eventPublisher->publishDomainEvents()->shouldBeCalledOnce();

        $this->publishEventsFromConsole(new ConsoleTerminateEvent(
            $command->getWrappedObject(),
            $input->getWrappedObject(),
            $output->getWrappedObject(),
            1
        ));
    }

    function it_publish_event_on_message_handling(
        EventPublisher $eventPublisher
    ) {
        $eventPublisher->publishDomainEvents()->shouldBeCalledOnce();

        $this->publishEventsFromWorker(new WorkerMessageHandledEvent(
            new Envelope(new \stdClass()),
            'receiver-name'
        ));
    }

    function it_wont_publish_event_on_http_termination(
        EventPublisher $eventPublisher,
        HttpKernelInterface $kernel,
        Request $request,
        Response $response
    ) {
        $this->beConstructedWith($eventPublisher, false);
        $eventPublisher->publishDomainEvents()->shouldNotBeCalled();

        $this->publishEventsFromHttp(new TerminateEvent(
            $kernel->getWrappedObject(),
            $request->getWrappedObject(),
            $response->getWrappedObject()
        ));
    }

    function it_not_publish_event_on_console_termination(
        EventPublisher $eventPublisher,
        Command $command,
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->beConstructedWith($eventPublisher, false);
        $eventPublisher->publishDomainEvents()->shouldNotBeCalled();

        $this->publishEventsFromConsole(new ConsoleTerminateEvent(
            $command->getWrappedObject(),
            $input->getWrappedObject(),
            $output->getWrappedObject(),
            1
        ));
    }

    function it_wont_publish_event_on_message_handling(
        EventPublisher $eventPublisher
    ) {
        $this->beConstructedWith($eventPublisher, false);
        $eventPublisher->publishDomainEvents()->shouldNotBeCalled();

        $this->publishEventsFromWorker(new WorkerMessageHandledEvent(
            new Envelope(new \stdClass()),
            'receiver-name'
        ));
    }
}
