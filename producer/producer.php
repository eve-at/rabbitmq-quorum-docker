<?php

declare(strict_types=1);

function connectToRabbitMQ(): AMQPConnection
{
    $hosts = explode(',', getenv('RABBITMQ_HOSTS') ?: 'rabbitmq1:5672');
    $user = getenv('RABBITMQ_USER') ?: 'admin';
    $pass = getenv('RABBITMQ_PASS') ?: 'admin';

    foreach ($hosts as $host) {
        [$hostname, $port] = explode(':', $host);
        
        try {
            echo "[" . date('Y-m-d H:i:s') . "] Attempting to connect to $hostname:$port\n";
            
            $connection = new AMQPConnection([
                'host' => $hostname,
                'port' => (int)$port,
                'vhost' => '/',
                'login' => $user,
                'password' => $pass,
                'read_timeout' => 3,
                'write_timeout' => 3,
                'connect_timeout' => 3,
            ]);
            
            $connection->connect();
            
            echo "[" . date('Y-m-d H:i:s') . "] Successfully connected to $hostname:$port\n";
            return $connection;
            
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Failed to connect to $hostname:$port - {$e->getMessage()}\n";
            continue;
        }
    }
    
    throw new Exception("Could not connect to any RabbitMQ node");
}

function setupQueue(AMQPChannel $channel): AMQPQueue
{
    $exchange = new AMQPExchange($channel);
    $exchange->setName('requests_exchange');
    $exchange->setType(AMQP_EX_TYPE_DIRECT);
    $exchange->setFlags(AMQP_DURABLE);
    $exchange->declareExchange();

    $queue = new AMQPQueue($channel);
    $queue->setName('requests_queue');
    $queue->setFlags(AMQP_DURABLE);
    $queue->setArguments([
        'x-queue-type' => 'quorum',
    ]);
    $queue->declareQueue();
    $queue->bind('requests_exchange', 'request');

    return $queue;
}

function generateRequest(int $counter): array
{
    $actions = ['subscribe', 'unsubscribe', 'state'];
    
    return [
        'action' => $actions[array_rand($actions)],
        'msisdn' => str_pad((string)rand(100000000, 999999999), 9, '0', STR_PAD_LEFT),
        'number' => $counter,
        'timestamp' => date('Y-m-d H:i:s'),
    ];
}

// Main loop
echo "[" . date('Y-m-d H:i:s') . "] Producer starting...\n";

$connection = null;
$channel = null;
$exchange = null;
$counter = 1;

while (true) {
    try {
        // Reconnect if needed
        if ($connection === null || !$connection->isConnected()) {
            $connection = connectToRabbitMQ();
            $channel = new AMQPChannel($connection);
            setupQueue($channel);
            
            $exchange = new AMQPExchange($channel);
            $exchange->setName('requests_exchange');
        }

        // Generate and send request
        $request = generateRequest($counter);
        $message = json_encode($request);
        
        $exchange->publish(
            $message,
            'request',
            AMQP_NOPARAM,
            [
                'delivery_mode' => 2, // persistent
                'content_type' => 'application/json',
            ]
        );
        
        echo "[" . date('Y-m-d H:i:s') . "] Sent request #{$counter}: {$request['action']} - {$request['msisdn']}\n";
        
        $counter++;
        sleep(2); // Send a request every 2 seconds
        
    } catch (AMQPConnectionException $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Connection lost: {$e->getMessage()}. Reconnecting...\n";
        $connection = null;
        $channel = null;
        $exchange = null;
        sleep(3);
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: {$e->getMessage()}\n";
        sleep(3);
    }
}