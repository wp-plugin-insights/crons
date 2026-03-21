<?php

declare(strict_types=1);

/**
 * Publishes plugin validation events to a RabbitMQ queue.
 *
 * Uses the php-amqp extension (ext-amqp). Install on Ubuntu/Debian:
 *   apt-get install php8.4-amqp
 *
 * The connection is established eagerly in the constructor so the process
 * fails immediately if RabbitMQ is unreachable, before any work is done.
 *
 * The target queue is declared as durable so messages survive broker restarts.
 * Messages are published as persistent (delivery_mode = 2).
 * Routing uses the AMQP default exchange: the queue name is the routing key.
 */
class RabbitMqPublisher
{
    private AMQPExchange $exchange;
    private string       $queueName;

    /**
     * @throws AMQPConnectionException If the broker is unreachable.
     * @throws AMQPChannelException    If the channel cannot be opened.
     */
    public function __construct(
        string $host,
        int    $port,
        string $login,
        string $password,
        string $vhost,
        string $queueName
    ) {
        $conn = new AMQPConnection();
        $conn->setHost($host);
        $conn->setPort($port);
        $conn->setLogin($login);
        $conn->setPassword($password);
        $conn->setVhost($vhost);
        $conn->connect();

        $channel = new AMQPChannel($conn);

        // Declare the queue as durable so it survives broker restarts.
        $queue = new AMQPQueue($channel);
        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        // Default exchange routes messages directly by queue name.
        $this->exchange  = new AMQPExchange($channel);
        $this->queueName = $queueName;
    }

    /**
     * Publishes a validation event as a persistent JSON message.
     *
     * @param array<string, mixed> $message
     *
     * @throws AMQPExchangeException On publish failure.
     */
    public function publish(array $message): void
    {
        $this->exchange->publish(
            json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->queueName,
            AMQP_NOPARAM,
            ['delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT]
        );
    }
}
