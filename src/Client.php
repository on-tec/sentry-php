<?php

declare(strict_types=1);

namespace Sentry;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Sentry\Integration\Handler;
use Sentry\Integration\IgnoreErrorsIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Scope;
use Sentry\Transport\ClosableTransportInterface;
use Sentry\Transport\TransportInterface;

/**
 * Default implementation of the {@see ClientInterface} interface.
 *
 * @author Stefano Arlandini <sarlandini@alice.it>
 */
final class Client implements FlushableClientInterface
{
    /**
     * The version of the protocol to communicate with the Sentry server.
     */
    public const PROTOCOL_VERSION = '7';

    /**
     * The identifier of the SDK.
     */
    public const SDK_IDENTIFIER = 'sentry.php';

    /**
     * @var Options The client options
     */
    private $options;

    /**
     * @var TransportInterface The transport
     */
    private $transport;

    /**
     * @var EventFactoryInterface The factory to create {@see Event} from raw data
     */
    private $eventFactory;

    /**
     * @var array<string, IntegrationInterface> The stack of integrations
     *
     * @psalm-var array<class-string<IntegrationInterface>, IntegrationInterface>
     */
    private $integrations;

    /**
     * Constructor.
     *
     * @param Options               $options      The client configuration
     * @param TransportInterface    $transport    The transport
     * @param EventFactoryInterface $eventFactory The factory for events
     */
    public function __construct(Options $options, TransportInterface $transport, EventFactoryInterface $eventFactory)
    {
        $this->options = $options;
        $this->transport = $transport;
        $this->eventFactory = $eventFactory;
        $this->integrations = Handler::setupIntegrations($options->getIntegrations());
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function captureMessage(string $message, ?Severity $level = null, ?Scope $scope = null): ?string
    {
        $payload = [
            'message' => $message,
            'level' => $level,
        ];

        return $this->captureEvent($payload, $scope);
    }

    /**
     * {@inheritdoc}
     */
    public function captureException(\Throwable $exception, ?Scope $scope = null): ?string
    {
        if (!isset($this->integrations[IgnoreErrorsIntegration::class]) && $this->options->isExcludedException($exception, false)) {
            return null;
        }

        return $this->captureEvent(['exception' => $exception], $scope);
    }

    /**
     * {@inheritdoc}
     */
    public function captureEvent(array $payload, ?Scope $scope = null): ?string
    {
        $event = $this->prepareEvent($payload, $scope);

        if (null === $event) {
            return null;
        }

        return $this->transport->send($event);
    }

    /**
     * @param array $event
     * @return string|null
     */
    public function sendEvent(array $event): ?string
    {
        return $this->transport->sendArray($event);
    }

    /**
     * {@inheritdoc}
     */
    public function captureLastError(?Scope $scope = null): ?string
    {
        $error = error_get_last();

        if (null === $error || !isset($error['message'][0])) {
            return null;
        }

        $exception = new \ErrorException(@$error['message'], 0, @$error['type'], @$error['file'], @$error['line']);

        return $this->captureException($exception, $scope);
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-template T of IntegrationInterface
     */
    public function getIntegration(string $className): ?IntegrationInterface
    {
        /** @psalm-var T|null */
        return $this->integrations[$className] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(?int $timeout = null): PromiseInterface
    {
        if (!$this->transport instanceof ClosableTransportInterface) {
            return new FulfilledPromise(true);
        }

        return $this->transport->close($timeout);
    }

    /**
     * Assembles an event and prepares it to be sent of to Sentry.
     *
     * @param array      $payload the payload that will be converted to an Event
     * @param Scope|null $scope   optional scope which enriches the Event
     *
     * @return Event|null returns ready to send Event, however depending on options it can be discarded
     */
    public function prepareEvent(array $payload, ?Scope $scope = null): ?Event
    {
        $sampleRate = $this->getOptions()->getSampleRate();

        if ($sampleRate < 1 && mt_rand(1, 100) / 100.0 > $sampleRate) {
            return null;
        }

        if ($this->getOptions()->shouldAttachStacktrace() && !isset($payload['exception']) && !isset($payload['stacktrace'])) {
            $event = $this->eventFactory->createWithStacktrace($payload);
        } else {
            $event = $this->eventFactory->create($payload);
        }

        if (null !== $scope) {
            $event = $scope->applyToEvent($event, $payload);

            if (null === $event) {
                return null;
            }
        }

        return \call_user_func($this->options->getBeforeSendCallback(), $event);
    }
}
