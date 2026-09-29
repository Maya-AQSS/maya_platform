<?php

namespace Maya\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maya\Messaging\Contracts\MessagePublisher;
use Throwable;

/**
 * Reintenta un publish AMQP fallido usando una cola de Laravel como buffer
 * cuando RabbitMQ no está disponible.
 *
 * Usado por AuditPublisher, NotificationPublisher y AlertPublisher.
 * LogPublisher queda excluido: sus mensajes ya van a disco vía canal
 * 'daily', el volumen es alto, y reintentar logs retrasados tiene poco
 * valor operativo.
 *
 * Conexión de cola (por orden): `messaging.retry.connection`; si no está
 * definida y la cola por defecto es `rabbitmq` (desarrollo), `database`, porque
 * reintentar RabbitMQ a través de RabbitMQ no tiene sentido; en cualquier otro
 * caso la cola por defecto de la app (en producción, Redis), que sí tiene un
 * `queue:work` que la procesa.
 *
 * Backoff exponencial: 30s → 60s → 120s → 300s → 600s (~17 min total).
 */
class RetryAmqpPublishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        private readonly string $exchange,
        private readonly string $routingKey,
        private readonly array $payload,
        private readonly array $properties = [],
    ) {
        $connection = self::resolveConnection();

        if ($connection !== null) {
            $this->connection = $connection;
        }
    }

    /**
     * @return string|null null → conexión de cola por defecto de la app
     */
    public static function resolveConnection(): ?string
    {
        $configured = config('messaging.retry.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return config('queue.default') === 'rabbitmq' ? 'database' : null;
    }

    public function handle(MessagePublisher $publisher): void
    {
        $publisher->publish(
            exchange: $this->exchange,
            routingKey: $this->routingKey,
            payload: $this->payload,
            properties: $this->properties,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('amqp.retry_exhausted', [
            'exchange'    => $this->exchange,
            'routing_key' => $this->routingKey,
            'tries'       => $this->tries,
            'error'       => $exception->getMessage(),
        ]);
    }
}
