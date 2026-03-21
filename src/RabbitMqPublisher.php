<?php

declare(strict_types=1);

namespace PluginInsight;

use AMQPChannel;
use AMQPChannelException;
use AMQPConnection;
use AMQPConnectionException;
use AMQPExchange;
use AMQPExchangeException;

/**
 * Publishes plugin validation events to a RabbitMQ exchange.
 *
 * Uses the php-amqp extension (ext-amqp). Install on Ubuntu/Debian:
 *   apt-get install php8.4-amqp
 *
 * Messages are published to a named fanout exchange. The exchange broadcasts
 * to all queues bound to it; the routing key is ignored by the broker.
 * Queue bindings must be set up externally (e.g. via the RabbitMQ management
 * console or infrastructure provisioning).
 *
 * The connection is established eagerly in the constructor so the process
 * fails immediately if RabbitMQ is unreachable, before any work is done.
 *
 * Messages are published as persistent (delivery_mode = 2) so they survive
 * broker restarts.
 */
class RabbitMqPublisher
{
    /** Declared exchange used for all publish calls. */
    private AMQPExchange $exchange;

    /**
     * @param string $host         Broker hostname or IP address.
     * @param int    $port         Broker AMQP port (default 5672).
     * @param string $login        AMQP username.
     * @param string $password     AMQP password.
     * @param string $vhost        Virtual host (default "/").
     * @param string $exchangeName Name of the fanout exchange to publish to.
     *
     * @throws AMQPConnectionException If the broker is unreachable.
     * @throws AMQPChannelException    If the channel cannot be opened.
     * @throws AMQPExchangeException   If the exchange cannot be declared.
     */
    public function __construct(
        string $host,
        int $port,
        string $login,
        string $password,
        string $vhost,
        string $exchangeName
    ) {
        $conn = new AMQPConnection();
        $conn->setHost($host);
        $conn->setPort($port);
        $conn->setLogin($login);
        $conn->setPassword($password);
        $conn->setVhost($vhost);
        $conn->connect();

        $channel = new AMQPChannel($conn);

        $exchange = new AMQPExchange($channel);
        $exchange->setName($exchangeName);
        $exchange->setType(AMQP_EX_TYPE_FANOUT);
        $exchange->setFlags(AMQP_DURABLE);
        $exchange->declareExchange();

        $this->exchange = $exchange;
    }

    /**
     * Publishes a validation event as a persistent JSON message.
     *
     * The message body is JSON-encoded from $message. The routing key is
     * empty because the exchange is a fanout (routing keys are ignored).
     *
     * @param array<string, mixed> $message Associative array to publish as JSON.
     *
     * @throws AMQPExchangeException On publish failure.
     */
    public function publish(array $message): void
    {
        $this->exchange->publish(
            json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '',
            AMQP_NOPARAM,
            ['delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT]
        );
    }
}
