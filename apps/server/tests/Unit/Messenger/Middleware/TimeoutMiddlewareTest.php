<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger\Middleware;

use App\Messenger\Attribute\WithTimeout;
use App\Messenger\Exception\MessageTimeoutException;
use App\Messenger\Middleware\TimeoutMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

#[WithTimeout(5)]
class MessageWithTimeout
{
    public function __construct(public readonly string $id = 'test')
    {
    }
}

class MessageWithoutTimeout
{
    public function __construct(public readonly string $id = 'test')
    {
    }
}

#[WithTimeout(1)]
class MessageWithShortTimeout
{
    public function __construct(public readonly string $id = 'test')
    {
    }
}

class TimeoutMiddlewareTest extends TestCase
{
    private TimeoutMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new TimeoutMiddleware(new NullLogger());
    }

    public function testSkipsTimeoutWhenDispatching(): void
    {
        // No ReceivedStamp means we're dispatching, not receiving
        $message = new MessageWithTimeout();
        $envelope = new Envelope($message);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $result = $this->middleware->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testSkipsTimeoutWhenNoAttribute(): void
    {
        $message = new MessageWithoutTimeout();
        $envelope = new Envelope($message, [new ReceivedStamp('async')]);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $result = $this->middleware->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testPassesThroughOnSuccess(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $message = new MessageWithTimeout();
        $envelope = new Envelope($message, [new ReceivedStamp('async')]);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willReturn($envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $result = $this->middleware->handle($envelope, $stack);

        $this->assertSame($envelope, $result);
    }

    public function testRethrowsExceptionFromHandler(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $message = new MessageWithTimeout();
        $envelope = new Envelope($message, [new ReceivedStamp('async')]);

        $expectedException = new \RuntimeException('Handler failed');

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willThrowException($expectedException);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler failed');

        $this->middleware->handle($envelope, $stack);
    }

    public function testMessageTimeoutExceptionContainsDetails(): void
    {
        $exception = new MessageTimeoutException(MessageWithTimeout::class, 60);

        $this->assertSame(MessageWithTimeout::class, $exception->messageClass);
        $this->assertSame(60, $exception->timeoutSeconds);
        $this->assertStringContainsString('60 seconds', $exception->getMessage());
        $this->assertStringContainsString(MessageWithTimeout::class, $exception->getMessage());
    }

    public function testWithTimeoutAttributeReturnsSeconds(): void
    {
        $reflection = new \ReflectionClass(MessageWithTimeout::class);
        $attributes = $reflection->getAttributes(WithTimeout::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame(5, $instance->seconds);
    }

    /**
     * @group slow
     */
    public function testTimeoutTriggersException(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $message = new MessageWithShortTimeout();
        $envelope = new Envelope($message, [new ReceivedStamp('async')]);

        // Create a handler that sleeps longer than the timeout
        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function () use ($envelope) {
                // Sleep for 2 seconds, but timeout is 1 second
                sleep(2);

                return $envelope;
            });

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        $this->expectException(MessageTimeoutException::class);

        $this->middleware->handle($envelope, $stack);
    }
}
