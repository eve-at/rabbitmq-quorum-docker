<?php

declare(strict_types=1);

const LOG_FILE = '/var/log/consumer/messages.log';

function connectToRabbitMQ(): array
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
            
            // Return both connection and hostname for logging
            return [
                'connection' => $connection,
                'hostname' => $hostname,
                'port' => $port
            ];
            
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Failed to connect to $hostname:$port - {$e->getMessage()}\n";
            continue;
        }
    }
    
    throw new Exception("Could not connect to any RabbitMQ node");
}

function setupQueue(AMQPChannel $channel): AMQPQueue
{
    $queue = new AMQPQueue($channel);
    $queue->setName('requests_queue');
    $queue->setFlags(AMQP_DURABLE);
    $queue->setArguments([
        'x-queue-type' => 'quorum',
    ]);
    $queue->declareQueue();

    return $queue;
}

function logMessage(string $message): void
{
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    file_put_contents(LOG_FILE, $message . PHP_EOL, FILE_APPEND);
    echo $message . PHP_EOL;
}

// Main loop
echo "[" . date('Y-m-d H:i:s') . "] Consumer starting...\n";
logMessage("[" . date('Y-m-d H:i:s') . "] Consumer started");

$connection = null;
$channel = null;
$queue = null;
$currentNode = 'unknown';

while (true) {
    try {
        // Reconnect if needed
        if ($connection === null || !$connection->isConnected()) {
            $connectionInfo = connectToRabbitMQ();
            $connection = $connectionInfo['connection'];
            $currentNode = $connectionInfo['hostname'];
            
            $channel = new AMQPChannel($connection);
            $queue = setupQueue($channel);
            
            echo "[" . date('Y-m-d H:i:s') . "] Ready to consume messages from node: $currentNode\n";
            logMessage("[" . date('Y-m-d H:i:s') . "] Consumer connected to node: $currentNode");
        }

        // Consume message
        $queue->consume(function (AMQPEnvelope $envelope, AMQPQueue $queue) use ($currentNode) {
            $messageBody = $envelope->getBody();
            $data = json_decode($messageBody, true);
            
            // Get exchange name and routing key for additional information
            $exchange = $envelope->getExchangeName();
            $routingKey = $envelope->getRoutingKey();
            
            $logEntry = sprintf(
                "[%s] Received message #%d | Node: %s | Action: %s | MSISDN: %s | Original timestamp: %s | Exchange: %s | Routing Key: %s",
                date('Y-m-d H:i:s'),
                $data['number'] ?? 'N/A',
                $currentNode,
                $data['action'] ?? 'N/A',
                $data['msisdn'] ?? 'N/A',
                $data['timestamp'] ?? 'N/A',
                $exchange ?: 'direct',
                $routingKey ?: 'none'
            );
            
            logMessage($logEntry);
            
            // Acknowledge the message
            $queue->ack($envelope->getDeliveryTag());
            
            // Simulate processing time
            usleep(500000); // 0.5 seconds
        });
        
    } catch (AMQPConnectionException $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Connection lost from $currentNode: {$e->getMessage()}. Reconnecting...\n";
        logMessage("[" . date('Y-m-d H:i:s') . "] Connection lost from node: $currentNode, attempting to reconnect...");
        $connection = null;
        $channel = null;
        $queue = null;
        $currentNode = 'unknown';
        sleep(3);
        
    } catch (AMQPQueueException $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Queue error on $currentNode: {$e->getMessage()}\n";
        sleep(3);
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error on $currentNode: {$e->getMessage()}\n";
        logMessage("[" . date('Y-m-d H:i:s') . "] Error on node $currentNode: {$e->getMessage()}");
        sleep(3);
    }
}