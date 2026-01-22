# RabbitMQ Quorum Queues Testing Environment

A Docker-based testing environment to demonstrate and test **RabbitMQ Quorum Queues** behavior in a clustered setup with fault tolerance scenarios.

## Overview

This project simulates a distributed messaging system with:
- **3 RabbitMQ nodes** in a cluster
- **1 PHP Producer** - continuously generates and sends messages
- **1 PHP Consumer** - processes messages from the queue
- **Quorum Queues** - ensuring message durability and preventing duplicates

The main goal is to test how RabbitMQ Quorum Queues handle node failures and maintain message consistency.

## Architecture

```
┌─────────────┐
│  Producer   │───┐
└─────────────┘   │
                  │   ┌──────────────┐    ┌──────────────┐     ┌──────────────┐
                  ├──>│  RabbitMQ 1  │───>│  RabbitMQ 2  │────>│  RabbitMQ 3  │
                  │   │   (Leader)   │    │  (Follower)  │     │  (Follower)  │
                  │   └──────────────┘    └──────────────┘     └──────────────┘
                  │          │                   │                    │
┌─────────────┐   │          └───────────────────┴────────────────────┘
│  Consumer   │<──┘                         Quorum Queue
└─────────────┘                     (replicated across all nodes)
```

## Project Structure

```
rabbitmq-quorum-docker/
├── docker-compose.yml         # Docker orchestration
├── producer/
│   ├── Dockerfile
│   └── producer.php           # Message producer
├── consumer/
│   ├── Dockerfile
│   └── consumer.php           # Message consumer
└── logs/
    └── messages.log           # Consumer processing log
```

## Prerequisites

- Docker
- Docker Compose
- At least 4GB RAM available for Docker

## Installation & Setup

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd rabbitmq-quorum-docker
   ```

2. **Create logs directory**
   ```bash
   mkdir -p logs
   ```

3. **Start the environment**
   ```bash
   docker-compose up -d
   ```

4. **Wait for cluster setup** (approximately 30 seconds)
   ```bash
   # Check cluster setup logs
   docker logs quorum-rabbitmq-cluster-setup
   
   # Should see: "Cluster setup completed successfully"
   ```

5. **Verify cluster status**
   ```bash
   docker exec quorum-rabbitmq1 rabbitmqctl cluster_status
   ```

   Expected output:
   ```
   Cluster status of node rabbit@rabbitmq1 ...
   Basics
   
   Cluster name: rabbit@rabbitmq1
   
   Disk Nodes
   
   rabbit@rabbitmq1
   rabbit@rabbitmq2
   rabbit@rabbitmq3
   
   Running Nodes
   
   rabbit@rabbitmq1
   rabbit@rabbitmq2
   rabbit@rabbitmq3
   ```

## How It Works

### Producer
- Simulates incoming GET requests with parameters: `action`, `msisdn`, `number`
- Sends a new message every 2 seconds
- Automatically reconnects to available RabbitMQ nodes on failure
- Actions: `subscribe`, `unsubscribe`, `state`

### Quorum Queue
- Type: **quorum** (based on Raft consensus algorithm)
- Replicated across all 3 nodes
- Requires majority (2 of 3 nodes) to operate
- Guarantees exactly-once delivery (no duplicates)

### Consumer
- Processes messages one by one
- Logs to both console and `logs/messages.log`
- Automatically reconnects on node failure
- Tracks which RabbitMQ node it's connected to

## Testing Fault Tolerance

### Scenario 1: Single Node Failure (Quorum Maintained ✅)

1. **Monitor logs in separate terminals**
   ```bash
   # Terminal 1: Producer logs
   docker logs -f quorum-producer
   
   # Terminal 2: Consumer logs
   docker logs -f quorum-consumer
   
   # Terminal 3: Message log file
   tail -f logs/messages.log
   ```

2. **Stop first RabbitMQ node**
   ```bash
   docker stop quorum-rabbitmq1
   ```

3. **Observe behavior:**
   - Producer reconnects to `rabbitmq2` or `rabbitmq3`
   - Consumer reconnects to available node
   - **Messages continue processing without loss**
   - Quorum is maintained (2 of 3 nodes alive)

   **Example logs:**
   ```
   [2026-01-22 12:00:15] Connection lost from rabbitmq1, attempting to reconnect...
   [2026-01-22 12:00:18] Successfully connected to rabbitmq2:5672
   [2026-01-22 12:00:19] Consumer connected to node: rabbitmq2
   [2026-01-22 12:00:20] Received message #347 | Node: rabbitmq2 | Action: subscribe | MSISDN: 123456789 | ...
   ```

4. **Restart the node**
   ```bash
   docker start quorum-rabbitmq1
   ```

### Scenario 2: Two Node Failure (Quorum Lost ❌)

1. **Stop two RabbitMQ nodes**
   ```bash
   docker stop quorum-rabbitmq1
   docker stop quorum-rabbitmq2
   ```

2. **Observe behavior:**
   - Consumer connects to `rabbitmq3` but **cannot process messages**
   - Producer may connect but **messages are not accepted**
   - Queue becomes unavailable (minority of nodes alive: 1 of 3)
   - **This is expected behavior** - quorum requires majority

   **Example logs:**
   ```
   [2026-01-22 12:03:53] Successfully connected to rabbitmq3:5672
   [2026-01-22 12:03:57] Consumer connected to node: rabbitmq3
   [2026-01-22 12:05:13] Queue error on rabbitmq3: Server connection error: 541, message: INTERNAL_ERROR
   ```

3. **Restore quorum by starting at least one node**
   ```bash
   docker start quorum-rabbitmq1
   # OR
   docker start quorum-rabbitmq2
   ```

4. **Observe recovery:**
   - Queue becomes available again
   - Processing resumes automatically
   - **No message duplicates** (guaranteed by quorum queues)

   **Example logs after recovery:**
   ```
   [2026-01-22 12:09:07] Consumer connected to node: rabbitmq1
   [2026-01-22 12:09:08] Received message #353 | Node: rabbitmq1 | Action: state | ...
   ```

## Verification

### Check for Message Duplicates
```bash
# Extract message numbers and check for duplicates
grep "Received message" logs/messages.log | awk -F'#' '{print $2}' | awk '{print $1}' | sort -n | uniq -d

# Empty output = no duplicates ✅
```

### Check Message Sequence
```bash
# View all received message numbers
grep "Received message" logs/messages.log | awk -F'#' '{print $2}' | awk '{print $1}' | sort -n
```

### Check Queue Status
```bash
docker exec quorum-rabbitmq1 rabbitmqctl list_queues name type messages leader members
```

Expected output:
```
Timeout: 60.0 seconds ...
Listing queues for vhost / ...
name            type    messages leader          members
requests_queue  quorum  0        rabbit@rabbitmq1 [rabbit@rabbitmq1,rabbit@rabbitmq2,rabbit@rabbitmq3]
```

## RabbitMQ Management UI

Access the management interface for any node:

- **Node 1:** http://localhost:15672
- **Node 2:** http://localhost:15673
- **Node 3:** http://localhost:15674

**Credentials:** `admin` / `admin`

In the UI you can:
- View queue type (quorum)
- See which nodes are hosting replicas
- Identify the current leader node
- Monitor message rates and node health

## Understanding Quorum Queue Behavior

### ✅ Guarantees
- **Exactly-once delivery** - no message duplicates
- **Message durability** - messages survive node crashes (if quorum maintained)
- **Automatic failover** - leader re-election on failure
- **Data consistency** - Raft consensus algorithm

### ❌ Limitations
- **Requires majority** - cannot operate with minority of nodes
  - 3 nodes: minimum 2 alive
  - 5 nodes: minimum 3 alive
- **Higher latency** - consensus adds overhead vs classic queues
- **More disk I/O** - replication to multiple nodes

### Quorum vs Classic Queues

| Feature | Quorum Queue | Classic Mirrored Queue |
|---------|-------------|----------------------|
| Consensus | Raft (strong consistency) | Master-slave replication |
| Minimum nodes | Majority (2 of 3) | 1 (master) |
| Duplicate prevention | ✅ Built-in | ❌ Possible on failover |
| Performance | Moderate | Higher |
| Recommended | ✅ Yes (modern apps) | ⚠️ Deprecated |

## Expected Message Flow

**Normal operation:**
```
[12:00:01] Sent request #1: subscribe - 123456789
[12:00:01] Received message #1 | Node: rabbitmq1 | Action: subscribe | MSISDN: 123456789
[12:00:03] Sent request #2: state - 987654321
[12:00:03] Received message #2 | Node: rabbitmq1 | Action: state | MSISDN: 987654321
```

**During single node failure:**
```
[12:05:15] Connection lost from rabbitmq1, attempting to reconnect...
[12:05:18] Successfully connected to rabbitmq2:5672
[12:05:19] Sent request #348: unsubscribe - 456789123
[12:05:19] Received message #348 | Node: rabbitmq2 | Action: unsubscribe | MSISDN: 456789123
```

**During quorum loss (2 nodes down):**
```
[12:10:20] Queue error on rabbitmq3: Server connection error: 541, message: INTERNAL_ERROR
# No messages processed until quorum restored
```

## Cleanup

```bash
# Stop all containers
docker-compose down

# Remove volumes and clean slate
docker-compose down -v

# Remove logs
rm -rf logs/*
```

## Troubleshooting

### Producer/Consumer continuously restarting
```bash
# Check RabbitMQ cluster is fully initialized
docker logs quorum-rabbitmq-cluster-setup

# Verify all nodes are healthy
docker exec quorum-rabbitmq1 rabbitmqctl cluster_status
```

### Cannot access Management UI
```bash
# Create admin user manually
docker exec quorum-rabbitmq1 rabbitmqctl add_user admin admin
docker exec quorum-rabbitmq1 rabbitmqctl set_user_tags admin administrator
docker exec quorum-rabbitmq1 rabbitmqctl set_permissions -p / admin ".*" ".*" ".*"
```

### Messages not being processed
```bash
# Check queue exists and has correct type
docker exec quorum-rabbitmq1 rabbitmqctl list_queues name type

# Verify quorum is available (at least 2 of 3 nodes running)
docker ps | grep rabbitmq
```

## Key Learnings

1. **Quorum queues require majority** - plan for this in production (use 3 or 5 nodes)
2. **No message loss on single node failure** - quorum maintains consistency
3. **Automatic client reconnection** - applications should implement retry logic
4. **Leader election is automatic** - handled by Raft consensus
5. **No duplicate messages** - even during failover scenarios

## Production Recommendations

- Use **odd number of nodes** (3, 5, or 7) for clear majority
- Monitor **quorum status** - losing quorum stops the queue
- Implement **publisher confirms** in producer for guaranteed delivery
- Set appropriate **timeouts** for connection and channel operations
- Use **load balancer** in front of RabbitMQ nodes for client connections
- Enable **monitoring** (Prometheus + Grafana) for cluster health

## References

- [RabbitMQ Quorum Queues Documentation](https://www.rabbitmq.com/docs/quorum-queues)
- [RabbitMQ Clustering Guide](https://www.rabbitmq.com/docs/clustering)
- [Raft Consensus Algorithm](https://raft.github.io/)

## License

MIT
