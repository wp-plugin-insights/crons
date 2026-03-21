<?php

declare(strict_types=1);

/**
 * Publishes plugin validation events to a RabbitMQ queue.
 *
 * This is a stub implementation: messages are serialized as JSON and appended
 * to a log file. Replace the body of publish() with a real AMQP connection
 * once RabbitMQ is available.
 *
 * Example replacement using php-amqplib:
 *
 *   use PhpAmqpLib\Connection\AMQPStreamConnection;
 *   use PhpAmqpLib\Message\AMQPMessage;
 *
 *   $conn    = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
 *   $channel = $conn->channel();
 *   $channel->queue_declare('plugin-validation', false, true, false, false);
 *   $channel->basic_publish(
 *       new AMQPMessage(json_encode($message), ['delivery_mode' => 2]),
 *       '',
 *       'plugin-validation'
 *   );
 *   $channel->close();
 *   $conn->close();
 */
class RabbitMqPublisher
{
    /**
     * @param string $logFile Absolute path to the file used by the stub implementation.
     */
    public function __construct(private readonly string $logFile)
    {
    }

    /**
     * Publishes a validation event.
     *
     * @param array<string, mixed> $message
     */
    public function publish(array $message): void
    {
        // TODO: Replace with real AMQP implementation (see class docblock).
        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
