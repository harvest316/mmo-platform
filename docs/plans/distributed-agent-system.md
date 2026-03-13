# Distributed Agent System Design

**Generated:** 2026-02-15
**Updated:** 2026-03-13 — Moved from 333Method to mmo-platform (cross-project concern). Merged Claude Max update. Phase 0 + Part 20 marked obsolete. Added Part 22 (Multi-Project Architecture).
**Status:** Planning Document
**Source:** Agent Task Output

---

## Table of Contents

- [Executive Summary](#executive-summary)
- [Current System Analysis](#current-system-analysis)
- [Proposed Distributed Architecture](#proposed-distributed-architecture)
  - [1. System Architecture Diagram](#1.-system-architecture-diagram)
  - [2. Communication Protocol Specification](#2.-communication-protocol-specification)
  - [3. Database Strategy](#3.-database-strategy)
  - [4. Real-Time Notifications Across Machines](#4.-real-time-notifications-across-machines)
  - [5. Mobile App Integration (Claude Android)](#5.-mobile-app-integration-claude-android)
  - [6. OpenClaw Integration](#6.-openclaw-integration)
  - [7. Security & Authentication](#7.-security-&-authentication)
  - [8. Failover & Redundancy](#8.-failover-&-redundancy)
  - [9. Implementation Phases](#9.-implementation-phases)
  - [10. Documentation Requirements](#10.-documentation-requirements)
- [Summary of Key Changes](#summary-of-key-changes)
  - [Critical Files for Implementation](#critical-files-for-implementation)
- [Part 2: MCP Integration Analysis](#part-2-mcp-integration-analysis-added-2026-02-15)
  - [MCP Overview](#mcp-model-context-protocol-overview)
  - [MCP vs WebSocket Comparison](#mcp-vs-websocket-architecture-comparison)
  - [Hybrid Architecture Recommendation](#hybrid-mcp--redis-architecture-recommendation)
  - [MCP Benefits & Security](#mcp-protocol-benefits)
- [Part 3: Cloud Infrastructure Cost Analysis](#part-3-cloud-infrastructure-cost-analysis)
  - [PostgreSQL Hosting Comparison](#postgresql-hosting-comparison)
  - [Redis Hosting Comparison](#redis-hosting-comparison)
  - [VPS Requirements Analysis](#vps-requirements-analysis)
  - [VPS Provider Comparison](#vps-provider-cost-comparison)
- [Part 4: Total Infrastructure Cost Estimate](#part-4-total-infrastructure-cost-estimate)
  - [Recommended Configuration](#recommended-configuration-production-ready)
  - [Cost Justification](#cost-justification)
  - [Alternative Configurations](#alternative-configurations)
  - [Revised Implementation Plan with MCP](#revised-implementation-plan-with-mcp)
    - [Phase 0: LLM Privacy Proxy — Direct Anthropic API (Do Now)](#phase-0-llm-privacy-proxy--direct-anthropic-api-do-now--immediate-cost-savings)
    - [Phase 0.5: MCP Pre-requisites](#phase-05-mcp-pre-requisites-do-now--before-phase-1)
- [Part Q: Operational Intelligence Dashboard Pages](#part-q-operational-intelligence-dashboard-pages-added-2026-03-04)
  - [Q.1 Pipeline Health Page](#q1-pipeline-health-page)
  - [Q.2 Per-Stage Performance Page](#q2-per-stage-performance-page)
  - [Q.3 Concurrency Monitor Widget](#q3-concurrency-monitor-widget)
  - [Q.4 Outreach Trust Panel](#q4-outreach-trust-panel)
  - [Q.5 API Health & Cost Page](#q5-api-health--cost-page)
  - [Q.6 Monitoring Audit Widget](#q6-monitoring-audit-widget)
  - [Q.7 Config Validator Widget](#q7-config-validator-widget)
  - [Q.8 Metrics Consistency Check](#q8-metrics-consistency-check)

---

Excellent! Now I have a comprehensive understanding of the current system. Let me create a detailed architectural plan for distributed agents.

# Distributed Agent System Architecture Plan

## Executive Summary

The current 333Method agent system is a **single-machine, database-driven multi-agent system** with real-time task spawning. This plan outlines the architecture to evolve it into a **distributed, multi-machine agent system** with OpenClaw integration and Claude Android mobile support for remote monitoring and control.

**Updated 2026-02-15:** Added comprehensive MCP (Model Context Protocol) integration analysis, cloud infrastructure cost comparison, and VPS requirements for distributed deployment.

**Key Recommendations:**

1. **Hybrid MCP + Redis Architecture:** Use MCP for external integrations (Claude Desktop, Android, OpenClaw) and Redis pub/sub for internal agent coordination
2. **Cloud Infrastructure:** Neon PostgreSQL ($7/month) + Upstash Redis (free tier) + Hetzner VPS ($16.50/month) = **$24/month total**
3. **10x Scale Cost:** 3-node Hetzner cluster + paid Neon/Upstash = **$134/month** (vs $1,000+/month on AWS/DigitalOcean)
4. **First-Year Total:** $1,168 ($24/month × 4 months dev + $134/month × 8 months prod)

## Current System Analysis

**Current Architecture:**

- **6 specialized agents**: Monitor, Triage, Developer, QA, Security, Architect
- **Database-centric coordination**: SQLite `agent_tasks`, `agent_messages`, `agent_logs`, `agent_state` tables
- **Execution models**:
  - Real-time spawning via `spawnAgentAsync()` when tasks created
  - Scheduled polling via cron (every 5 minutes)
  - Locking mechanism via `agent_state.status` and `last_active` timestamp
- **Communication**: Inter-agent messaging via `agent_messages` table
- **Token efficiency**: 75-85% reduction vs monolithic (20-25KB context vs 100-150KB)

**Current Limitations for Distribution:**

1. SQLite is file-based (not network-accessible)
2. No cross-machine task distribution
3. No remote monitoring/control interface
4. Single point of failure (one machine)
5. Limited horizontal scaling

---

## Proposed Distributed Architecture

### 1. System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     CONTROL PLANE                                │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐    │
│  │  PostgreSQL  │  │    Redis     │  │  WebSocket Server  │    │
│  │  (Tasks DB)  │  │  (PubSub +   │  │  (Real-time Comms) │    │
│  │              │  │   Locks)     │  │                    │    │
│  └──────────────┘  └──────────────┘  └────────────────────┘    │
│         │                 │                    │                 │
└─────────┼─────────────────┼────────────────────┼─────────────────┘
          │                 │                    │
          │                 │                    │
┌─────────┴─────────────────┴────────────────────┴─────────────────┐
│                     MESSAGE BUS (Redis Pub/Sub)                   │
│  Topics: task.created, task.completed, agent.notification,       │
│          approval.required, progress.update, mobile.command       │
└─────────┬─────────────────┬────────────────────┬─────────────────┘
          │                 │                    │
    ┌─────┴─────┐    ┌─────┴─────┐       ┌─────┴─────┐
    │ Machine 1 │    │ Machine 2 │       │ Machine N │
    │           │    │           │       │           │
    │ Monitor   │    │ Developer │       │ QA        │
    │ Triage    │    │ Developer │       │ Security  │
    │ Architect │    │ Developer │       │           │
    └───────────┘    └───────────┘       └───────────┘
          │                 │                    │
          │                 │                    │
┌─────────┴─────────────────┴────────────────────┴─────────────────┐
│                     INTEGRATION LAYER                             │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐     │
│  │   OpenClaw   │  │ Claude API   │  │  Mobile WebSocket  │     │
│  │  (Planning)  │  │  (Execution) │  │    (Android App)   │     │
│  └──────────────┘  └──────────────┘  └────────────────────┘     │
└───────────────────────────────────────────────────────────────────┘
```

### 2. Communication Protocol Specification

#### 2.1 Message Format (JSON over Redis Pub/Sub + WebSocket)

```javascript
{
  "version": "1.0",
  "message_id": "uuid-v4",
  "timestamp": "2026-02-15T10:30:00Z",

  "type": "task.created" | "task.completed" | "agent.notification" |
         "approval.required" | "progress.update" | "mobile.command",
  "from": {

    "agent_name": "developer",
    "machine_id": "machine-1",
    "instance_id": "dev-worker-3"
  },
  "to": {

    "agent_name": "qa" | "*" | null,  // null = broadcast
    "machine_id": "machine-2" | "*" | null,
    "channel": "mobile" | "agents" | "openclaw"
  },

  "payload": {
    // Type-specific payload
  },
  "priority": 1-10,

  "requires_ack": true | false,
  "correlation_id": "parent-message-uuid"  // For request/response

}
```

#### 2.2 Message Types

**1. Task Distribution:**

```javascript
{
  "type": "task.created",
  "payload": {
    "task_id": 12345,
    "task_type": "fix_bug",
    "assigned_to": "developer",
    "priority": 8,
    "context": { /* task context */ },
    "affinity": "machine-2"  // Optional: prefer specific machine
  }
}
```

**2. Progress Updates (for Mobile):**

```javascript
{
  "type": "progress.update",
  "to": { "channel": "mobile" },
  "payload": {
    "task_id": 12345,
    "agent_name": "developer",
    "status": "running",
    "progress_percent": 45,
    "current_step": "Running unit tests",
    "estimated_completion": "2026-02-15T10:35:00Z"
  }
}
```

**3. Approval Requests:**

```javascript
{
  "type": "approval.required",
  "to": { "channel": "mobile" },
  "payload": {
    "task_id": 12345,

    "approval_type": "git_push" | "schema_change" | "api_key_rotation",
    "description": "Push 3 commits to main branch",

    "context": {
      "commits": ["abc123", "def456"],
      "files_changed": 5,
      "tests_passing": true
    },
    "timeout_seconds": 300,
    "default_action": "deny"
  }
}
```

**4. Mobile Commands:**

```javascript
{
  "type": "mobile.command",
  "from": { "channel": "mobile", "user_id": "jason" },
  "payload": {

    "command": "approve" | "reject" | "pause" | "resume" | "ask_question",
    "task_id": 12345,

    "reason": "Looks good, proceed",
    "parameters": { /* command-specific */ }
  }
}
```

**5. Agent Questions:**

```javascript
{
  "type": "agent.question",
  "to": { "channel": "mobile" },
  "payload": {
    "task_id": 12345,
    "agent_name": "qa",
    "question": "Should I write integration tests for external API calls?",
    "options": ["yes", "no", "skip_for_now"],
    "context": {
      "file": "src/outreach/email.js",
      "current_coverage": "78%"
    }
  }
}
```

#### 2.3 Transport Protocols

**Primary: Redis Pub/Sub**

- Fast, low-latency messaging
- Topic-based routing
- At-most-once delivery (use acks for critical messages)
- Channels:
  - `task:created:{agent_name}` - Task distribution
  - `task:completed:{task_id}` - Task results
  - `agent:notifications` - Broadcast notifications
  - `mobile:updates` - Mobile app updates
  - `mobile:commands` - Commands from mobile

**Secondary: WebSocket (for Mobile + OpenClaw)**

- Persistent connection for real-time updates
- Bidirectional communication
- Reconnection logic with exponential backoff
- Authentication via JWT tokens

**Fallback: PostgreSQL LISTEN/NOTIFY**

- Database-backed messaging when Redis unavailable
- Lower throughput but guaranteed delivery

### 3. Database Strategy

#### 3.1 Migration from SQLite to PostgreSQL

**Why PostgreSQL:**

- Network-accessible (no file locking)
- LISTEN/NOTIFY for pub/sub
- Better concurrency (MVCC vs SQLite's lock)
- Horizontal read scaling via replicas
- JSON/JSONB support for context storage

**Migration Strategy:**

**Phase 1: Dual-Write (Week 1-2)**

- Keep SQLite as primary
- Write to both SQLite + PostgreSQL
- Read from SQLite
- Verify data consistency

**Phase 2: Dual-Read (Week 3)**

- Write to both
- Read from PostgreSQL for new operations
- Read from SQLite for legacy queries
- Monitor performance

**Phase 3: PostgreSQL Primary (Week 4)**

- PostgreSQL becomes primary
- SQLite writes disabled
- Keep SQLite as cold backup

**Phase 4: Decommission SQLite (Week 5+)**

- Archive SQLite database
- Remove dual-write code

#### 3.2 Schema Changes for Distribution

**New Tables:**

```sql
-- Machine registry
CREATE TABLE agent_machines (
    machine_id VARCHAR(50) PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL,
    ip_address INET,
    region VARCHAR(50),  -- 'us-east', 'au-sydney', etc.
    capabilities JSONB,  -- {"max_memory": 8GB, "gpu": false, "agents": ["developer"]}
    status VARCHAR(20) CHECK(status IN ('online', 'offline', 'draining')),
    last_heartbeat TIMESTAMP,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    INDEX idx_machines_status (status, last_heartbeat)
);

-- Agent instances (multiple per machine)
CREATE TABLE agent_instances (
    instance_id VARCHAR(50) PRIMARY KEY,
    machine_id VARCHAR(50) REFERENCES agent_machines(machine_id),
    agent_name VARCHAR(50) NOT NULL,
    status VARCHAR(20) CHECK(status IN ('idle', 'working', 'blocked', 'dead')),
    current_task_id INTEGER REFERENCES agent_tasks(id),
    pid INTEGER,  -- OS process ID
    memory_mb INTEGER,
    cpu_percent DECIMAL(5,2),
    last_heartbeat TIMESTAMP,
    started_at TIMESTAMP,
    INDEX idx_instances_agent (agent_name, status),
    INDEX idx_instances_machine (machine_id, status)
);

-- Distributed locks (Redis-backed, PostgreSQL fallback)
CREATE TABLE distributed_locks (
    lock_key VARCHAR(100) PRIMARY KEY,
    instance_id VARCHAR(50) REFERENCES agent_instances(instance_id),
    acquired_at TIMESTAMP DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    metadata JSONB,
    INDEX idx_locks_expires (expires_at)
);

-- Mobile sessions
CREATE TABLE mobile_sessions (
    session_id UUID PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    device_type VARCHAR(20),  -- 'android', 'ios'
    device_token VARCHAR(255),  -- For push notifications
    websocket_connection_id VARCHAR(100),
    last_active TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    INDEX idx_sessions_user (user_id, last_active)
);

-- Approval queue
CREATE TABLE approval_queue (
    approval_id SERIAL PRIMARY KEY,
    task_id INTEGER REFERENCES agent_tasks(id),
    approval_type VARCHAR(50) NOT NULL,
    description TEXT,
    context_json JSONB,
    status VARCHAR(20) DEFAULT 'pending' CHECK(status IN ('pending', 'approved', 'rejected', 'timeout')),
    requested_at TIMESTAMP DEFAULT NOW(),
    responded_at TIMESTAMP,
    responder VARCHAR(50),  -- user_id or 'system'
    timeout_at TIMESTAMP,
    default_action VARCHAR(20),
    INDEX idx_approvals_status (status, requested_at)
);
```

**Modified Tables:**

```sql
-- Add distribution fields to agent_tasks
ALTER TABLE agent_tasks
ADD COLUMN machine_id VARCHAR(50) REFERENCES agent_machines(machine_id),
ADD COLUMN instance_id VARCHAR(50) REFERENCES agent_instances(instance_id),
ADD COLUMN claimed_at TIMESTAMP,
ADD COLUMN claim_expires_at TIMESTAMP,
ADD COLUMN execution_region VARCHAR(50);

-- Add routing metadata
ALTER TABLE agent_messages
ADD COLUMN machine_id VARCHAR(50),
ADD COLUMN delivery_status VARCHAR(20) DEFAULT 'pending',
ADD COLUMN delivered_at TIMESTAMP;
```

#### 3.3 Centralized vs Distributed Database

**Recommendation: Hybrid Approach**

**Centralized PostgreSQL (Primary)**

- Single source of truth for tasks, state, logs
- Simplifies consistency
- Easier to query/debug
- Read replicas for scaling

**Distributed Redis (Ephemeral State)**

- Real-time locks (TTL-based)
- Message pub/sub
- Rate limiting counters
- Session state

**Regional Caching (Optional - Phase 3)**

- PostgreSQL read replicas in each region
- Local Redis instances
- Eventual consistency acceptable for logs

### 4. Real-Time Notifications Across Machines

#### 4.1 Architecture

```
Task Created → PostgreSQL INSERT
              ↓
       PostgreSQL NOTIFY
              ↓
       Redis PUBLISH (task:created:{agent})
              ↓
    ┌─────────┴──────────┬──────────────┐
    ↓                    ↓               ↓
Machine 1           Machine 2        Mobile App
(Monitor)           (Developer)      (WebSocket)
    ↓                    ↓               ↓
Poll Redis          Poll Redis       Receive Push
Claim Task          Claim Task       Show Notification
```

#### 4.2 Task Claiming Protocol (Distributed Lock)

**Redis-based (Primary):**

```javascript
// Attempt to claim task
const claimed = await redis.set(
  `task:claim:${taskId}`,
  instanceId,
  'NX', // Only set if not exists
  'EX',
  30 // Expire in 30 seconds
);

if (claimed) {
  // Update PostgreSQL
  await db.query(
    `
    UPDATE agent_tasks
    SET status = 'running',
        instance_id = $1,
        machine_id = $2,
        claimed_at = NOW(),
        claim_expires_at = NOW() + INTERVAL '30 seconds'
    WHERE id = $3 AND status = 'pending'
    RETURNING id
  `,
    [instanceId, machineId, taskId]
  );
}
```

**PostgreSQL-based (Fallback):**

```sql
-- Optimistic locking with row-level locks
UPDATE agent_tasks
SET status = 'running',
    instance_id = $1,
    machine_id = $2,
    claimed_at = NOW()
WHERE id = $3
  AND status = 'pending'
  AND (claim_expires_at IS NULL OR claim_expires_at < NOW())
RETURNING id;
```

#### 4.3 Heartbeat System

**Agent Instance Heartbeat (every 10 seconds):**

```javascript
setInterval(async () => {
  // Update Redis
  await redis.setex(
    `heartbeat:${instanceId}`,
    30,
    JSON.stringify({
      status: 'working',
      current_task_id: taskId,
      memory_mb: process.memoryUsage().rss / 1024 / 1024,
      cpu_percent: os.loadavg()[0],
    })
  );

  // Update PostgreSQL (every 60 seconds)
  if (Date.now() % 60000 < 10000) {
    await db.query(
      `
      UPDATE agent_instances
      SET last_heartbeat = NOW(),
          memory_mb = $1,
          cpu_percent = $2
      WHERE instance_id = $3
    `,
      [memoryMb, cpuPercent, instanceId]
    );
  }
}, 10000);
```

**Dead Instance Detection:**

```javascript
// Cron job every 60 seconds
const deadInstances = await db.query(`
  SELECT instance_id, current_task_id
  FROM agent_instances
  WHERE status = 'working'
    AND last_heartbeat < NOW() - INTERVAL '2 minutes'
`);

for (const instance of deadInstances) {
  // Mark instance as dead
  await db.query(
    `
    UPDATE agent_instances
    SET status = 'dead'
    WHERE instance_id = $1
  `,
    [instance.instance_id]
  );

  // Release task if claimed
  if (instance.current_task_id) {
    await db.query(
      `
      UPDATE agent_tasks
      SET status = 'pending',
          instance_id = NULL,
          claimed_at = NULL,
          error_message = 'Agent instance died'
      WHERE id = $1 AND status = 'running'
    `,
      [instance.current_task_id]
    );

    // Notify other agents
    await redis.publish(
      'task:created',
      JSON.stringify({
        task_id: instance.current_task_id,
        reason: 'failover',
      })
    );
  }
}
```

### 5. Mobile App Integration (Claude Android)

#### 5.1 WebSocket Server

**Technology:** Node.js + `ws` library or `socket.io`

**Endpoints:**

```javascript
// WebSocket server (separate service or embedded in API)
import WebSocket from 'ws';

const wss = new WebSocket.Server({ port: 8080 });

wss.on('connection', async (ws, req) => {
  // Authenticate via JWT
  const token = req.headers.authorization?.replace('Bearer ', '');
  const user = await verifyJWT(token);

  if (!user) {
    ws.close(4001, 'Unauthorized');
    return;
  }

  // Register session
  const sessionId = uuidv4();
  await db.query(
    `
    INSERT INTO mobile_sessions (session_id, user_id, device_type, websocket_connection_id)
    VALUES ($1, $2, $3, $4)
  `,
    [sessionId, user.id, user.device_type, ws.id]
  );

  // Subscribe to user's notifications
  const subscriber = redis.duplicate();
  await subscriber.subscribe(`mobile:${user.id}:updates`);

  subscriber.on('message', (channel, message) => {
    ws.send(message);
  });

  // Handle commands from mobile
  ws.on('message', async data => {
    const command = JSON.parse(data);

    switch (command.type) {
      case 'approve':
        await handleApproval(command.task_id, user.id, 'approved');
        break;
      case 'reject':
        await handleApproval(command.task_id, user.id, 'rejected');
        break;
      case 'ask_question':
        await sendQuestionToAgent(command.task_id, command.question);
        break;
    }
  });

  ws.on('close', async () => {
    await db.query(`DELETE FROM mobile_sessions WHERE session_id = $1`, [sessionId]);
    await subscriber.quit();
  });
});
```

#### 5.2 Mobile Message Types

**1. Task Notifications:**

```json
{
  "type": "task.notification",
  "task_id": 12345,
  "agent": "developer",
  "title": "Bug fix in progress",
  "body": "Fixing null pointer in scoring.js",
  "priority": "high",
  "actions": ["view", "cancel"]
}
```

**2. Approval Requests:**

```json
{
  "type": "approval.request",
  "task_id": 12345,
  "title": "Approve git push?",
  "body": "Push 3 commits to main (5 files changed, tests passing)",
  "actions": [
    { "id": "approve", "label": "Approve", "style": "primary" },
    { "id": "reject", "label": "Reject", "style": "danger" },
    { "id": "view_diff", "label": "View Diff", "style": "secondary" }
  ],
  "timeout": 300
}
```

**3. Progress Updates:**

```json
{
  "type": "progress.update",
  "task_id": 12345,
  "status": "running",
  "steps": [
    { "name": "Analyze error", "status": "completed" },
    { "name": "Generate fix", "status": "completed" },
    { "name": "Run tests", "status": "running", "progress": 45 },
    { "name": "Create commit", "status": "pending" }
  ]
}
```

**4. Questions:**

```json
{
  "type": "agent.question",
  "task_id": 12345,
  "agent": "qa",
  "question": "Should I write integration tests for Twilio API?",
  "options": [
    { "id": "yes", "label": "Yes, write integration tests" },
    { "id": "no", "label": "No, unit tests only" },
    { "id": "defer", "label": "Defer to architect" }
  ]
}
```

#### 5.3 Claude Android Integration

**Authentication Flow:**

```
1. User opens Claude Android app
2. App shows "Connect to 333Method Agents" button
3. User taps → Opens OAuth flow
4. Backend generates JWT with scopes: ['agents:read', 'agents:approve', 'agents:command']
5. App stores JWT + establishes WebSocket connection
6. App subscribes to push notifications
```

**UI Components:**

```
┌────────────────────────────────┐
│  333Method Agent Dashboard     │
├────────────────────────────────┤
│ Active Tasks (3)               │
│ ┌────────────────────────────┐│
│ │ 🔧 Developer                ││
│ │ Fixing null pointer bug     ││
│ │ Progress: ████░░░░ 45%      ││
│ │ [View] [Cancel]             ││
│ └────────────────────────────┘│
│ ┌────────────────────────────┐│
│ │ ⚠️ Approval Required        ││
│ │ Push 3 commits to main?     ││
│ │ [Approve] [Reject] [Diff]   ││
│ └────────────────────────────┘│
│                                │
│ Recent Activity (12)           │
│ System Health: ✅ Good         │
│ [Settings] [Logs]              │
└────────────────────────────────┘
```

### 6. OpenClaw Integration

**Note:** Previously referenced as "OpenClaw" - corrected to **OpenClaw** (the actual tool name).

#### 6.1 OpenClaw as Planning Agent

**Role:** High-level task decomposition and architecture decisions

**Integration Points:**

```javascript
// OpenClaw → 333Method Agent System
async function submitOpenClawPlan(plan) {
  // OpenClaw outputs architectural plan
  const tasks = parsePlan(plan);

  // Create tasks in agent system
  for (const task of tasks) {
    const taskId = await createAgentTask({
      task_type: task.type,
      assigned_to: task.agent,
      priority: task.priority,
      context: {
        ...task.context,
        source: 'openclaw',
        plan_id: plan.id,
      },
    });

    // Notify OpenClaw of task creation
    await notifyOpenClaw({
      type: 'task.created',
      task_id: taskId,
      plan_id: plan.id,
    });
  }
}

// 333Method → OpenClaw (task completion)
async function notifyOpenClaw(event) {
  await redis.publish('openclaw:events', JSON.stringify(event));

  // Also HTTP webhook if configured
  if (process.env.OPENCLAW_WEBHOOK_URL) {
    await axios.post(process.env.OPENCLAW_WEBHOOK_URL, event);
  }
}
```

#### 6.2 Workflow Example

```
1. User (via mobile): "Add dark mode to dashboard"
   ↓
2. OpenClaw: Analyzes codebase, creates plan
   ↓
3. Plan Tasks:
   - Architect: Review dark mode design patterns
   - Developer: Implement CSS variables + theme toggle
   - QA: Write tests for theme switching
   - Security: Verify no XSS in dynamic CSS
   ↓
4. 333Method Agents: Execute tasks in sequence
   ↓
5. Mobile App: Shows progress updates
   ↓
6. Approval Required: "Push dark mode feature?"
   ↓
7. User approves via mobile
   ↓
8. Developer Agent: Pushes to GitHub
   ↓
9. OpenClaw: Marks plan as complete
```

### 7. Security & Authentication

#### 7.1 Machine Authentication

**Mutual TLS (mTLS):**

- Each machine has X.509 certificate
- Redis + PostgreSQL verify client certs
- Certificate rotation every 90 days

**API Keys (Fallback):**

- Machine-specific API keys stored in env vars
- Sent via `Authorization: Bearer <key>` header
- Rate-limited per machine

#### 7.2 Mobile Authentication

**JWT Tokens:**

- Short-lived access tokens (15 minutes)
- Long-lived refresh tokens (30 days)
- Scopes: `agents:read`, `agents:approve`, `agents:command`

**WebSocket Security:**

- WSS (WebSocket over TLS)
- JWT verification on connect
- Heartbeat/ping-pong to detect dead connections

#### 7.3 Approval Permissions

**RBAC (Role-Based Access Control):**

```sql
CREATE TABLE user_roles (
    user_id VARCHAR(50) NOT NULL,
    role VARCHAR(50) NOT NULL,
    granted_at TIMESTAMP DEFAULT NOW(),
    PRIMARY KEY (user_id, role)
);

-- Roles: 'admin', 'approver', 'viewer', 'developer'
```

**Permission Matrix:**

| Action                 | Admin | Approver | Viewer |
| ---------------------- | ----- | -------- | ------ |
| View tasks             | ✅    | ✅       | ✅     |
| Approve git push       | ✅    | ✅       | ❌     |
| Approve schema changes | ✅    | ❌       | ❌     |
| Cancel tasks           | ✅    | ✅       | ❌     |
| Spawn agents           | ✅    | ❌       | ❌     |

### 8. Failover & Redundancy

#### 8.1 Agent Failover

**Health Checks:**

- Heartbeat every 10 seconds
- Dead instance detection after 2 minutes
- Automatic task reassignment

**Task Recovery:**

```javascript
// Failover cron (every 60 seconds)
async function recoverOrphanedTasks() {
  const orphaned = await db.query(`
    SELECT id, assigned_to, retry_count
    FROM agent_tasks
    WHERE status = 'running'
      AND claim_expires_at < NOW()
      AND retry_count < 3
  `);

  for (const task of orphaned) {
    // Reset task to pending
    await db.query(
      `
      UPDATE agent_tasks
      SET status = 'pending',
          instance_id = NULL,
          machine_id = NULL,
          claimed_at = NULL,
          retry_count = retry_count + 1
      WHERE id = $1
    `,
      [task.id]
    );

    // Notify agents
    await redis.publish(
      `task:created:${task.assigned_to}`,
      JSON.stringify({
        task_id: task.id,
        reason: 'failover',
        retry_count: task.retry_count + 1,
      })
    );
  }
}
```

#### 8.2 Database Redundancy

**PostgreSQL High Availability:**

- Primary-replica setup (streaming replication)
- Automatic failover via Patroni or pg_auto_failover
- Read replicas for scaling

**Redis High Availability:**

- Redis Sentinel (3-node setup)
- Automatic failover on primary failure
- AOF + RDB persistence for durability

**Backup Strategy:**

- PostgreSQL: Daily full backups + WAL archiving
- Redis: RDB snapshots every 6 hours
- Retention: 30 days

#### 8.3 Network Partition Handling

**Split-Brain Prevention:**

- Quorum-based locking (require majority of machines)
- Fencing tokens (monotonic counter prevents stale locks)
- Witness node for tie-breaking

**Partition Detection:**

```javascript
// Detect network partition
async function checkNetworkPartition() {
  const machines = await db.query(`
    SELECT machine_id, last_heartbeat
    FROM agent_machines
    WHERE status = 'online'
  `);

  const reachable = await Promise.all(machines.map(m => ping(m.ip_address)));

  const unreachable = machines.filter((m, i) => !reachable[i]);

  if (unreachable.length > machines.length / 2) {
    // We're in minority partition - go into read-only mode
    logger.error('Network partition detected - entering read-only mode');
    process.env.READ_ONLY_MODE = 'true';
  }
}
```

### 9. Implementation Phases

#### Phase 1: Database Migration (Weeks 1-4)

**Effort: 40 hours Claude Code, 80 hours Human**

**Tasks:**

1. Set up PostgreSQL instance (4h)
2. Migrate schema from SQLite to PostgreSQL (8h)
3. Implement dual-write layer (12h)
4. Write data migration script (8h)
5. Test dual-write consistency (8h)

**Deliverables:**

- PostgreSQL schema matching SQLite
- Dual-write middleware
- Data migration script
- Consistency tests

#### Phase 2: Redis Pub/Sub Infrastructure (Weeks 5-6)

**Effort: 30 hours Claude Code, 60 hours Human**

**Tasks:**

1. Set up Redis cluster (4h)
2. Implement message bus abstraction (10h)
3. Add pub/sub to task creation (6h)
4. Add heartbeat system (6h)
5. Test failover scenarios (4h)

**Deliverables:**

- Redis cluster setup
- Message bus library ([`src/distributed/message-bus.js`](/home/jason/code/333Method/src/distributed/message-bus.js))
- Heartbeat monitoring
- Failover tests

#### Phase 3: Distributed Task Claiming (Weeks 7-8)

**Effort: 35 hours Claude Code, 70 hours Human**

**Tasks:**

1. Implement Redis-based distributed locks (8h)
2. Modify BaseAgent for distributed claiming (10h)
3. Add machine registry (6h)
4. Add instance registration (6h)
5. Test multi-machine task distribution (5h)

**Deliverables:**

- Distributed lock manager
- Updated BaseAgent with claiming protocol
- Machine/instance registration
- Multi-machine tests

#### Phase 4: WebSocket Server + Mobile API (Weeks 9-11)

**Effort: 50 hours Claude Code, 100 hours Human**

**Tasks:**

1. Build WebSocket server (15h)
2. Implement mobile authentication (10h)
3. Create mobile message handlers (10h)
4. Build approval queue system (10h)
5. Create mobile API documentation (5h)

**Deliverables:**

- WebSocket server ([`src/distributed/websocket-server.js`](/home/jason/code/333Method/src/distributed/websocket-server.js))
- Mobile authentication service
- Approval queue system
- API documentation

#### Phase 5: OpenClaw Integration (Weeks 12-13)

**Effort: 25 hours Claude Code, 50 hours Human**

**Tasks:**

1. Define OpenClaw ↔ Agent protocol (6h)
2. Build plan ingestion service (8h)
3. Create task decomposition logic (6h)
4. Add OpenClaw webhooks (5h)

**Deliverables:**

- OpenClaw integration service
- Plan parser
- Webhook handlers
- Integration tests

#### Phase 6: Mobile App Development (Weeks 14-18)

**Effort: 20 hours Claude Code (backend), 100 hours Human (Android)**

**Tasks:**

1. Build mobile backend API (10h Claude)
2. Create Android UI components (40h Human)
3. Implement WebSocket client (20h Human)
4. Add push notifications (20h Human)
5. Write mobile tests (20h Human)

**Deliverables:**

- Mobile backend API
- Android app with dashboard
- Push notification system
- Mobile test suite

#### Phase 7: Production Hardening (Weeks 19-20)

**Effort: 30 hours Claude Code, 60 hours Human**

**Tasks:**

1. Set up monitoring (Prometheus + Grafana) (10h)
2. Implement circuit breakers (8h)
3. Add comprehensive logging (6h)
4. Write runbooks (6h)

**Deliverables:**

- Monitoring dashboards
- Circuit breakers
- Centralized logging
- Operations runbooks

### 10. Documentation Requirements

**File: `[DISTRIBUTED-AGENTS.md](/home/jason/code/333Method/docs/DISTRIBUTED-AGENTS.md)`**

**Contents:**

1. Architecture overview with diagrams
2. Setup guide (PostgreSQL, Redis, WebSocket server)
3. Configuration reference (env vars)
4. Message protocol specification
5. Mobile integration guide
6. OpenClaw integration guide
7. Troubleshooting guide
8. Operations runbook (deployment, scaling, failover)
9. Security best practices
10. Performance tuning guide

**Additional Documentation:**

- `[MOBILE-API.md](/home/jason/code/333Method/docs/MOBILE-API.md)` - Mobile API reference
- `[CODECLAW-INTEGRATION.md](/home/jason/code/333Method/docs/CODECLAW-INTEGRATION.md)` - OpenClaw protocol
- `[DISTRIBUTED-DEPLOYMENT.md](/home/jason/code/333Method/docs/DISTRIBUTED-DEPLOYMENT.md)` - Deployment guide

---

## Summary of Key Changes

**Infrastructure:**

- PostgreSQL replaces SQLite (network-accessible, concurrent)
- Redis for pub/sub + distributed locks
- WebSocket server for real-time mobile communication

**Agent Changes:**

- Machine/instance registration
- Distributed task claiming with Redis locks
- Heartbeat monitoring
- Failover handling

**New Services:**

- WebSocket server for mobile/OpenClaw
- Approval queue service
- Mobile authentication service
- OpenClaw plan ingestion service

**Mobile Features:**

- Real-time task notifications
- Approval requests
- Progress updates
- Agent questions
- Task kickoff from mobile

**OpenClaw Integration:**

- Plan → Task decomposition
- High-level architectural planning
- Workflow coordination
- Progress reporting back to OpenClaw

---

### Critical Files for Implementation

- **[message-bus.js](/home/jason/code/333Method/src/distributed/message-bus.js)** - Core messaging abstraction (Redis pub/sub + PostgreSQL LISTEN/NOTIFY fallback)
- **[lock-manager.js](/home/jason/code/333Method/src/distributed/lock-manager.js)** - Distributed lock management for task claiming across machines
- **[websocket-server.js](/home/jason/code/333Method/src/distributed/websocket-server.js)** - WebSocket server for mobile app + OpenClaw real-time communication
- **[048-distributed-agents.sql](/home/jason/code/333Method/db/migrations/048-distributed-agents.sql)** - Database schema changes for multi-machine support
- **[base-agent.js](/home/jason/code/333Method/src/agents/base-agent.js)** - Modify for distributed task claiming and machine awareness

---

## Part 2: MCP Integration Analysis (Added 2026-02-15)

### MCP (Model Context Protocol) Overview

**What is MCP?**

The Model Context Protocol (MCP) is an open standard created by Anthropic for connecting AI assistants to data systems such as content repositories, business tools, and development environments. Announced in November 2024 and donated to the Agentic AI Foundation (AAIF) under the Linux Foundation in December 2025, MCP has become industry-standard infrastructure with:

- **500+ publicly available servers** (databases, file storage, web scraping, APIs, etc.)
- **97 million monthly SDK downloads** (Python and TypeScript)
- **10,000+ active servers** in production
- Native support in Claude Desktop, Claude Android app, and other platforms

### MCP vs WebSocket Architecture Comparison

#### Transport Layer Analysis

**MCP Transport Options:**

1. **stdio** - Standard input/output (local processes)
2. **Streamable HTTP** - HTTP-based streaming (currently supported)
3. **WebSocket transport** - Proposed (SEP-1288) but not yet in official spec

MCP communication uses **WebSocket + JSON-RPC 2.0** for:

- Stateful, low-latency, bidirectional connections
- Interactive sessions where agents fetch data, wait, think, then take actions
- Smaller per-frame overhead vs HTTP/2
- Simpler semantics than HTTP/2 multiplexing

**WebSocket + Redis Pub/Sub (Current Plan):**

- Fast, low-latency messaging via Redis channels
- Topic-based routing (`task:created:{agent}`, `mobile:updates`)
- At-most-once delivery (with acks for critical messages)
- PostgreSQL LISTEN/NOTIFY fallback when Redis unavailable

**Key Insight:** MCP and WebSockets are **complementary**, not competing technologies. MCP standardizes the data layer and tool interface, while WebSockets provide the high-performance transport layer.

#### Authentication & Security

**MCP Authentication (2026):**

- **OAuth 2.1** standards with multi-layer security
- MCP servers classified as **OAuth Resource Servers**
- **JWT tokens** for authentication (short-lived access tokens: 15 min, long-lived refresh tokens: 30 days)
- Discovery mechanism for Authorization Server location
- **Resource Indicators** to prevent malicious servers from obtaining access tokens

**WebSocket + JWT (Current Plan):**

- WSS (WebSocket over TLS)
- JWT verification on connect
- Scopes: `agents:read`, `agents:approve`, `agents:command`
- Heartbeat/ping-pong for dead connection detection

**Verdict:** Both approaches use JWT + OAuth 2.1 standards. Security posture is equivalent.

### Hybrid MCP + Redis Architecture Recommendation

**Recommendation: Use HYBRID approach - MCP for external integrations, Redis for internal agent coordination**

#### Why Hybrid?

**Use MCP for:**

1. **Claude Desktop integration** - Native MCP support for monitoring agents from desktop app
2. **Claude Android app** - Custom MCP connectors for mobile monitoring (paid plans: Pro, Max, Team, Enterprise)
3. **OpenClaw integration** - Standardized protocol for planning agent communication
4. **External tool integrations** - Leverage 500+ existing MCP servers (Google Drive, Slack, GitHub, Postgres, Puppeteer, etc.)
5. **Future-proofing** - Industry standard backed by Linux Foundation

**Use Redis Pub/Sub + WebSocket for:**

1. **Internal agent coordination** - Fast, low-latency task claiming and messaging between distributed agent instances
2. **Real-time task distribution** - Sub-millisecond pub/sub for `task:created` notifications
3. **Distributed locks** - Redis TTL-based locks for task claiming (faster than database-backed locks)
4. **Session state** - Ephemeral state storage (agent heartbeats, in-progress tasks)
5. **Mobile real-time updates** - WebSocket for push notifications and approval requests

#### Architecture Diagram: Hybrid MCP + Redis

```
┌─────────────────────────────────────────────────────────────────┐
│                     CONTROL PLANE                                │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐    │
│  │  PostgreSQL  │  │    Redis     │  │  WebSocket Server  │    │
│  │  (Tasks DB)  │  │  (PubSub +   │  │  (Real-time Comms) │    │
│  │              │  │   Locks)     │  │                    │    │
│  └──────────────┘  └──────────────┘  └────────────────────┘    │
│         │                 │                    │                 │
└─────────┼─────────────────┼────────────────────┼─────────────────┘
          │                 │                    │
          │                 │                    │
┌─────────┴─────────────────┴────────────────────┴─────────────────┐
│              MESSAGE BUS (Redis Pub/Sub + MCP)                    │
│  Internal: task.created, task.completed, agent.notification       │
│  External: MCP Tools, MCP Resources, MCP Prompts                  │
└─────────┬─────────────────┬────────────────────┬─────────────────┘
          │                 │                    │
    ┌─────┴─────┐    ┌─────┴─────┐       ┌─────┴─────┐
    │ Machine 1 │    │ Machine 2 │       │ Machine N │
    │           │    │           │       │           │
    │ Monitor   │    │ Developer │       │ QA        │
    │ Triage    │    │ Developer │       │ Security  │
    │ Architect │    │ Developer │       │           │
    └───────────┘    └───────────┘       └───────────┘
          │                 │                    │
          │                 │                    │
┌─────────┴─────────────────┴────────────────────┴─────────────────┐
│                  INTEGRATION LAYER (MCP)                          │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐     │
│  │   OpenClaw   │  │ Claude API   │  │  Mobile MCP        │     │
│  │  (Planning)  │  │  (Execution) │  │  (Android Custom   │     │
│  │  MCP Server  │  │              │  │   Connector)       │     │
│  └──────────────┘  └──────────────┘  └────────────────────┘     │
└───────────────────────────────────────────────────────────────────┘
```

#### Implementation Strategy

**Phase 1: Core Agent Coordination (Redis/WebSocket)**

- Implement Redis pub/sub for internal agent messaging
- Distributed locks for task claiming
- PostgreSQL for task persistence
- WebSocket server for real-time mobile updates

**Phase 2: MCP Integration Layer**

- Build MCP server exposing agent system as tools:
  - `agent:create_task` - Create new agent task
  - `agent:get_status` - Get task/agent status
  - `agent:approve_action` - Approve pending actions
  - `agent:view_logs` - Read agent execution logs
- Implement OAuth 2.1 authentication
- Register with Anthropic Connectors Directory (for verified status)

**Phase 3: External Integrations**

- Claude Desktop connector (monitoring dashboard)
- Claude Android custom connector (mobile approvals)
- OpenClaw MCP server integration (planning tasks)

### MCP Protocol Benefits

1. **Standardization** - Interoperable with 500+ existing servers
2. **Tool Discovery** - Dynamic tool registration via MCP protocol
3. **Session Management** - Stateful connections with conversation history
4. **Type Safety** - JSON Schema validation for tool inputs/outputs
5. **Versioning** - Protocol version negotiation for backward compatibility
6. **Resource Management** - Standardized access to files, databases, APIs
7. **Claude Native Integration** - First-class support in Claude Desktop and mobile apps

### MCP Security Considerations

1. **Custom Connectors** - Only available on paid Claude plans (Pro, Max, Team, Enterprise)
2. **OAuth Resource Indicators** - Prevents token theft by malicious MCP servers
3. **Short-lived Tokens** - 15-minute access tokens reduce attack window
4. **Authorization Server Discovery** - Ensures tokens requested from legitimate authority
5. **Scoped Permissions** - Granular control over agent:read, agent:approve, agent:command
6. **Audit Trail** - All MCP requests logged to `agent_logs` table

---

## Part 3: Cloud Infrastructure Cost Analysis

> **Update 2026-02-23:** Hostinger KVM 1 is pre-paid for a year ($131.88). PostgreSQL will be
> **self-hosted on the VPS** — no managed cloud DB needed. Neon/Supabase/Railway costs below are
> historical context only. See **Part K** for the revised architecture and cost implications.

### Current System Statistics

**Database Size:**

- **SQLite file size:** 1.4 GB (as of 2026-02-15)
- **Total sites:** 66,996
- **Tables:** sites, outreaches, conversations, agent_tasks, agent_messages, agent_logs, agent_state
- **Growth rate:** ~100 MB initially, estimated 5 GB/year growth

**Query Load (Estimated):**

- **Agent activity:** ~10-50 queries/min during normal operation
- **Peak activity:** ~100-500 queries/min during pipeline runs
- **Concurrent connections:** 5-10 agent instances + web dashboard + mobile app

### PostgreSQL Hosting Comparison

#### 1. Neon (Serverless Postgres) - WINNER

**Pricing:**

- **Free Tier:**
  - 100 CU-hours/month (doubled from 50 in Oct 2025)
  - 0.5 GB storage per project (up to 5 GB across 10 projects)
  - Auto-scaling up to 2 CU
  - Scale-to-zero with 5-minute idle timeout
  - 6 hours point-in-time recovery
  - **Monthly cost: $0**
- **Paid Plans (Launch):**
  - Storage: **$0.35/GB-month** (dropped from $1.75 - 80% reduction)
  - Compute: Usage-based with $5 minimum
  - History/Backups: **$0.20/GB-month** (WAL retention)
  - Snapshots: Free during beta, then cheaper than standard storage
- **Estimated Cost for 333Method:**
  - 5 GB database: $1.75/month
  - 30-day WAL history (~2 GB): $0.40/month
  - Compute (minimal usage): ~$5/month
  - **Total: ~$7-8/month**

**Pros:**

- Cheapest option with serverless pricing
- Auto-scaling (0-2 CU on free tier)
- Instant branching for testing
- Point-in-time recovery included
- No idle charges when scaled to zero
- Backed by Databricks (AWS discounts passed to customers)

**Cons:**

- Free tier limited to 0.5 GB (would need paid plan)
- 5-minute scale-to-zero timeout (potential cold start latency)
- Newer provider (less enterprise track record)

#### 2. Supabase (PostgreSQL BaaS)

**Pricing:**

- **Free Tier:**
  - 500 MB database
  - Unlimited API requests
  - 50,000 monthly active users
  - 7-day backup retention
  - **Monthly cost: $0**
- **Paid Plans (Pro):**
  - $25/month base
  - 8 GB database included
  - Additional: $0.125/GB
  - 7-day point-in-time recovery
- **Estimated Cost for 333Method:**
  - **Total: $25/month** (5 GB within included 8 GB)

**Pros:**

- Includes auth, realtime subscriptions, file storage, row-level security
- No cold starts (instances don't spin down)
- Built-in Postgres extensions
- Good for rapid prototyping

**Cons:**

- Higher baseline cost ($25/month vs Neon's $7-8/month)
- Overkill features (auth, storage) not needed for agent system
- Fixed pricing regardless of actual usage

#### 3. Railway (Usage-Based PaaS)

**Pricing:**

- **Free Tier:**
  - $5 credit/month
  - Usage-based (RAM hours, CPU hours, storage)
- **Paid Plans:**
  - Pay-as-you-go: RAM + CPU + storage
  - Estimated: ~$10-15/month for small Postgres instance
- **Estimated Cost for 333Method:**
  - 1 GB RAM, 1 vCPU, 5 GB storage: **$10-12/month**

**Pros:**

- True usage-based pricing (no waste)
- Good for multi-service architectures
- Easy deployment pipeline

**Cons:**

- More complex pricing calculation
- Less Postgres-specific features than Neon/Supabase
- Requires careful monitoring to avoid surprise bills

#### 4. DigitalOcean Managed Postgres

**Pricing:**

- **Entry Plan:**
  - $15/month for 1 GB RAM, 10 GB storage, 1 vCPU
  - Automatic failover
  - Daily backups included
  - Point-in-time recovery available
- **Estimated Cost for 333Method:**
  - **Total: $15/month**

**Pros:**

- Predictable flat pricing
- Proven reliability
- Simple configuration
- Good documentation

**Cons:**

- No free tier
- No auto-scaling
- Higher cost than Neon for small workloads

#### 5. AWS RDS PostgreSQL

**Pricing:**

- **Free Tier (1 year):**
  - 750 hours/month db.t3.micro (1 vCPU, 1 GB RAM)
  - 20 GB General Purpose SSD
  - 20 GB automated backups
- **Paid Plans:**
  - db.t4g.micro (2 vCPU, 1 GB RAM): ~$15/month + storage + I/O + backups
  - Storage: $0.115/GB-month
  - I/O: $0.20 per 1M requests
  - Backups: $0.095/GB-month
- **Estimated Cost for 333Method:**
  - Instance: $15/month
  - Storage (5 GB): $0.58/month
  - Backups (5 GB): $0.48/month
  - I/O (50M requests): $10/month
  - **Total: ~$26/month**

**Pros:**

- Enterprise-grade reliability
- Extensive monitoring/logging
- Multi-AZ failover

**Cons:**

- Complex pricing (need spreadsheet to estimate)
- Expensive for small workloads
- Overkill for agent system

### Redis Hosting Comparison

#### 1. Upstash Redis (Serverless) - WINNER

**Pricing:**

- **Free Tier:**
  - 256 MB data size
  - 500K commands/month (increased from 10K daily in March 2025)
  - Up to 10 databases
  - **Monthly cost: $0**
- **Fixed Plans:**
  - 250 MB: $10/month (+ $5 per read region)
  - 1 GB: $40/month
  - 5 GB: $200/month
- **Pay-as-you-go:**
  - First 200 GB bandwidth free
  - Storage: Usage-based
- **Estimated Cost for 333Method:**
  - Agent coordination uses minimal storage (<100 MB for locks/sessions)
  - Pub/sub is ephemeral (no storage cost)
  - **Total: $0/month** (fits in free tier)

**Pros:**

- Generous free tier (500K commands/month)
- Serverless with per-request pricing
- Global edge replication available
- Perfect for agent coordination (low storage, high commands)

**Cons:**

- Command limits on free tier (but 500K/month is plenty)
- Storage limits (but agent coordination needs <100 MB)

#### 2. Render Redis

**Pricing:**

- **Free Tier:** Available
- **Paid Plans:** ~$7/month for basic instance
- **Estimated Cost:** $7/month

**Pros:**

- Simple pricing
- Free tier available

**Cons:**

- Less feature-rich than Upstash
- No serverless scaling

#### 3. Redis Cloud (Redis Inc.)

**Pricing:**

- **Free Tier:**
  - 30 MB (very limited)
- **Paid Plans:**
  - Start at $5/month for 100 MB
  - Scale up quickly ($100+/month for multi-GB)
- **Estimated Cost:** $5-10/month

**Pros:**

- Official Redis offering
- Enterprise features (Redis Sentinel, Redis Cluster)

**Cons:**

- Expensive for small workloads
- Overkill for agent coordination

### VPS Requirements Analysis

#### Current Workload Characteristics

**Memory-Intensive Components:**

1. **Playwright browsers:** 1 GB per concurrent browser instance
2. **Node.js agents:** ~150-300 MB per agent process (5-10 concurrent)
3. **Pipeline processing:** LLM calls (scoring, enrichment) - minimal local memory
4. **Redis:** 30% overhead recommended (e.g., 256 MB usage → 370 MB allocated)
5. **Background cron jobs:** ~200-400 MB total

**CPU Requirements:**

- **Playwright:** 1 vCPU per concurrent browser (headless mode)
- **Node.js agents:** 1-2 vCPU for 5-10 concurrent instances
- **Database queries:** Minimal CPU (mostly I/O bound)
- **Image processing (Sharp):** CPU-intensive for screenshot cropping

**Storage Requirements:**

- **Code + dependencies:** ~500 MB (node_modules)
- **Screenshots:** 66K sites × ~100 KB cropped = 6.6 GB (grows 5-10 GB/year)
- **Logs:** 7-day rotation, ~500 MB total
- **Docker images:** ~2 GB (Node, Playwright, dependencies)

#### Recommended VPS Specifications

**Single-Node Setup (Current Scale - 67K sites):**

- **RAM:** 8 GB
  - Playwright: 2 GB (2 concurrent browsers)
  - Node.js agents: 1.5 GB (5 agents × 300 MB)
  - Redis: 500 MB (256 MB usage + 30% overhead)
  - OS + overhead: 2 GB
  - Buffer: 2 GB
- **vCPU:** 4 cores
  - Playwright: 2 cores
  - Agents + pipeline: 2 cores
- **Storage:** 50 GB SSD
  - OS + code: 5 GB
  - Screenshots: 10 GB (current + buffer)
  - Docker images: 5 GB
  - Logs + backups: 5 GB
  - Growth buffer: 25 GB
- **Bandwidth:** 2-3 TB/month (SERP scraping, API calls)

**Multi-Node Distributed Setup (10x Growth - 670K sites):**

**Node 1 (Monitor/Triage/Architect - Lightweight):**

- **RAM:** 4 GB
- **vCPU:** 2 cores
- **Storage:** 20 GB SSD

**Node 2-3 (Developer/QA/Security - Heavy Playwright):**

- **RAM:** 16 GB each
  - Playwright: 8 GB (8 concurrent browsers for screenshot capture)
  - Node.js agents: 3 GB (10 agents × 300 MB)
  - OS + overhead: 5 GB
- **vCPU:** 8 cores each
  - Playwright: 6 cores
  - Agents: 2 cores
- **Storage:** 100 GB SSD each
  - Screenshots: 60 GB (distributed across nodes)
  - Docker + code: 20 GB
  - Buffer: 20 GB

**Total Multi-Node Cost:**

- 1× 4 GB + 2× 16 GB = 36 GB RAM total
- 1× 2 vCPU + 2× 8 vCPU = 18 vCPU total
- 1× 20 GB + 2× 100 GB = 220 GB storage total

### VPS Provider Cost Comparison

#### Hetzner (Best Value) - WINNER

**Single-Node (8 GB RAM, 4 vCPU, 160 GB SSD):**

- **Model:** CX41
- **Price:** €15.23/month (~$16.50 USD)
- **Specs:** 8 GB RAM, 4 vCPU, 160 GB SSD, 20 TB traffic
- **Location:** Germany, Finland, USA

**Multi-Node (36 GB RAM, 18 vCPU):**

- 1× CX21 (4 GB, 2 vCPU, 40 GB): €5.39/month
- 2× CCX33 (16 GB, 8 vCPU, 160 GB): €47.80/month each
- **Total:** €101/month (~$110 USD)

**Pros:**

- Best price-to-performance ratio
- Generous storage and bandwidth
- Data centers in EU and USA
- Excellent reputation for reliability

**Cons:**

- Fewer locations than Vultr/DigitalOcean
- EU-based (GDPR compliance, but may have latency for US users)

#### DigitalOcean

**Single-Node (8 GB RAM, 4 vCPU, 160 GB SSD):**

- **Model:** Basic Droplet
- **Price:** $48/month
- **Specs:** 8 GB RAM, 4 vCPU, 160 GB SSD, 5 TB transfer

**Multi-Node:**

- 1× 4 GB: $24/month
- 2× 16 GB: $96/month each
- **Total:** $216/month

**Pros:**

- Polished UI and documentation
- Many data center locations
- Good ecosystem (managed databases, load balancers)

**Cons:**

- 3x more expensive than Hetzner
- Lower storage allocation

#### Vultr

**Single-Node (8 GB RAM, 4 vCPU, 180 GB SSD):**

- **Model:** High Frequency
- **Price:** $48/month
- **Specs:** 8 GB RAM, 4 vCPU, 180 GB SSD, 6 TB bandwidth

**Multi-Node:**

- 1× 4 GB: $24/month
- 2× 16 GB: $96/month each
- **Total:** $216/month

**Pros:**

- 32+ data center locations (best geographic coverage)
- Good performance benchmarks

**Cons:**

- Similar pricing to DigitalOcean (expensive vs Hetzner)

#### Linode (Akamai)

**Single-Node (8 GB RAM, 4 vCPU, 160 GB SSD):**

- **Model:** Dedicated 8 GB
- **Price:** $36/month
- **Specs:** 8 GB RAM, 4 vCPU, 160 GB SSD, 5 TB transfer

**Multi-Node:**

- 1× 4 GB: $18/month
- 2× 16 GB: $72/month each
- **Total:** $162/month

**Pros:**

- Good balance of price and features
- Reliable uptime track record
- Akamai CDN integration

**Cons:**

- Still 2x more expensive than Hetzner

---

## Part 4: AgentFlow Standalone Project Integration (Added 2026-02-16)

> **Update 2026-02-23:** See **Part L** for the explicit GitHub repo structure, PostgreSQL schema
> separation strategy, and concrete migration path. The concept below is sound; Part L makes it
> specific.

### AgentFlow as Separate NPM Package

**Key Insight:** The distributed agent system can be packaged as a standalone npm package (`@agentflow/core`) that ANY Node.js project can use, not just 333Method. This lives in its own separate GitHub repository.

#### Architecture: Two Separate Systems

```
AgentFlow (Standalone npm Package)
├─ PostgreSQL database (agent tasks, messages, logs)
├─ Redis (distributed locking, pub/sub)
├─ Own codebase, own repo, own infrastructure
└─ Generic multi-agent system (Monitor, Triage, Developer, QA, Security, Architect)

333Method Pipeline (Client of AgentFlow)
├─ PostgreSQL database (sites, outreaches, conversations)
├─ Redis (pipeline coordination)
├─ Installs AgentFlow: npm install @agentflow/core
└─ AgentFlow monitors 333Method logs and fixes bugs automatically
```

#### Key Benefits

1. **Reusability**: AgentFlow can be used across multiple projects (333Method, AgentFlow itself, other Node.js apps)
2. **Separation of Concerns**: Pipeline infrastructure separate from agent infrastructure
3. **Self-Healing**: AgentFlow agents can monitor and fix bugs in BOTH 333Method AND AgentFlow codebases
4. **Open Source Potential**: AgentFlow can be open-sourced as a generic development automation tool

#### AgentFlow Self-Monitoring

**How AgentFlow monitors itself:**

```javascript
// AgentFlow configuration
{
  projectRoot: '/path/to/agentflow',  // Monitor AgentFlow's own codebase
  logDir: './logs',
  testCommand: 'npm test',            // AgentFlow's own tests
  database: { url: 'postgresql://agentflow-db' }
}
```

**How AgentFlow monitors 333Method:**

```javascript
// 333Method configuration
{
  projectRoot: '/path/to/333Method',  // Monitor 333Method's codebase
  logDir: './logs',
  testCommand: 'npm test',            // 333Method's tests
  database: { url: 'postgresql://agentflow-db' }  // Same agent DB
}
```

**Single AgentFlow instance managing multiple projects:**

- Monitor agent scans logs from BOTH projects
- Triage agent classifies errors from BOTH codebases
- Developer agent creates fixes in the appropriate project
- QA agent runs the appropriate test suite
- Security agent audits BOTH codebases

#### Cost Implications

**VPS Option (Self-Hosted):**

- Hetzner VPS (8 GB RAM, 4 vCPU): $16.50/month
- Install PostgreSQL + Redis via Docker
- Total: $16.50/month (vs $54/month cloud-managed)

**Docker Compose Setup:**

```yaml
services:
  postgres:
    image: postgres:15
    volumes:
      - pgdata:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
  agentflow-worker:
    image: agentflow/worker:latest
    depends_on: [postgres, redis]
```

**Cloud Backup Strategy:**

- Daily PostgreSQL backups to Backblaze B2 ($0.005/GB/month)
- 5 GB database = $0.025/month for backups
- Total with backups: $16.53/month

#### Migration Path

1. **Week 1-4**: Migrate current agents to cloud PostgreSQL/Redis (stay on single VPS)
2. **Week 5-8**: Extract agents into @agentflow/core package structure
3. **Week 9-12**: Test AgentFlow monitoring both itself and 333Method
4. **Week 13+**: Add distributed multi-machine support when needed

See full AgentFlow separation plan in background task output for detailed implementation strategy.

---

## Part 5: Total Infrastructure Cost Estimate

### Recommended Configuration (Production-Ready)

#### Phase 1: Single-Node Deployment (Current Scale - 67K sites)

**Cloud Services:**

- **PostgreSQL (Neon):** $7-8/month
- **Redis (Upstash Free Tier):** $0/month
- **VPS (Hetzner CX41):** $16.50/month (8 GB RAM, 4 vCPU, 160 GB SSD)

**Total Monthly Cost: ~$24/month**

**Annual Cost: ~$288/year**

#### Phase 2: Multi-Node Deployment (10x Scale - 670K sites)

**Cloud Services:**

- **PostgreSQL (Neon):** $15/month (15 GB database + backups)
- **Redis (Upstash 250MB Fixed):** $10/month (distributed locks + pub/sub)
- **VPS Nodes (Hetzner):**
  - 1× CX21 (4 GB, 2 vCPU): $5.90/month
  - 2× CCX33 (16 GB, 8 vCPU): $52/month each
  - **Subtotal:** $109/month

**Total Monthly Cost: ~$134/month**

**Annual Cost: ~$1,608/year**

### Cost Comparison: Current vs Distributed

| Component           | Current (SQLite + Single Machine) | Distributed (Postgres + Multi-Node) | Difference         |
| ------------------- | --------------------------------- | ----------------------------------- | ------------------ |
| Database            | $0 (SQLite file)                  | $7-15/month (Neon)                  | +$7-15/month       |
| Coordination        | $0 (SQLite tables)                | $0/month (Upstash free)             | $0                 |
| Compute (Single)    | $16.50/month (Hetzner CX41)       | $16.50/month                        | $0                 |
| Compute (Multi)     | N/A                               | $109/month (3 Hetzner nodes)        | +$109/month        |
| **Total (Phase 1)** | **$16.50/month**                  | **$24/month**                       | **+$7.50/month**   |
| **Total (Phase 2)** | **$16.50/month**                  | **$134/month**                      | **+$117.50/month** |

### Cost Justification

**Phase 1 (+$7.50/month = 45% increase):**

- **Benefits:**
  - Multi-machine agent distribution (horizontal scaling)
  - Network-accessible database (no file locking)
  - Real-time mobile monitoring
  - Point-in-time recovery (6 hours on free tier, 30 days on paid)
  - MCP integration for Claude Desktop/Android
  - OpenClaw planning agent coordination
- **ROI:** Enables remote agent monitoring and coordination for <$8/month

**Phase 2 (+$117.50/month = 712% increase):**

- **Benefits:**
  - 10x processing capacity (670K sites)
  - Distributed Playwright rendering (24 concurrent browsers vs 2)
  - Fault tolerance (agent failover across nodes)
  - Regional distribution (EU + US nodes)
  - Dedicated agent specialization (Monitor/Triage vs Developer/QA/Security)
- **ROI:** Scales to 670K sites for $134/month (vs $1,000+/month on AWS/DigitalOcean)

### Alternative Configurations

#### Budget Option (Minimize Cost)

- **PostgreSQL:** Neon Free Tier ($0 - fits 5 GB across 10 projects)
- **Redis:** Upstash Free Tier ($0 - 256 MB, 500K commands)
- **VPS:** Hetzner CX21 (4 GB RAM, 2 vCPU, 40 GB SSD) - $5.90/month

**Total: $5.90/month** (64% cheaper than Phase 1 recommendation)

**Tradeoffs:**

- Limited to 5 GB database (current 1.4 GB + 3.6 GB growth buffer)
- Only 2 GB RAM for Playwright (1 concurrent browser max)
- No point-in-time recovery (Neon free tier: 6 hours only)

#### Enterprise Option (Maximum Reliability)

- **PostgreSQL:** AWS RDS Multi-AZ ($50/month)
- **Redis:** Redis Cloud with Sentinel ($20/month)
- **VPS:** DigitalOcean 3-node cluster ($216/month)
- **Load Balancer:** $12/month
- **Managed Backups:** $10/month

**Total: $308/month**

**Benefits:**

- Multi-AZ failover (99.95% SLA)
- Automated backups with 30-day retention
- DDoS protection and monitoring
- Enterprise support

**Tradeoffs:**

- 2.3x more expensive than Hetzner-based solution
- Overkill for agent system (no public-facing traffic)

---

## Revised Implementation Plan with MCP

### Phase 0: LLM Proxy — ~~LiteLLM Gateway~~ [OBSOLETE — Claude Max]

> **Superseded (2026-03-10):** Claude Max subscription ($200/mo flat) replaced API-based LLM execution. 90%+ of LLM work now runs through `claude -p` (zero marginal cost, subscription auth — cannot be proxied). The remaining OpenRouter calls (scoring/enrichment) are optional and controlled by feature flags (`ENABLE_LLM_SCORING`, `ENABLE_ENRICHMENT_LLM`), costing ~$8/day at current volume. LiteLLM's value propositions (cost routing, budget enforcement, provider abstraction) are moot when there is minimal API traffic. Usage tracking is handled by the batch orchestrator's logging. Cost monitoring for residual OpenRouter calls uses `npm run credits`.

<details><summary>Original Phase 0 content (archived)</summary>

**Effort: 4–6 hours Claude Code, 12–16 hours human**

**Why first:** We discovered $1,871 in unexpected OpenRouter spend — $1,160 on Sonnet 4.6 alone
from untracked calls. The immediate fix (Haiku + caching) cut 95%, but the systemic problem
remains: application code holds API keys, can bypass tracking, and is locked to a single provider.
The LLM Proxy (Part 20) solves all of this by design.

**Build vs Buy decision:** LiteLLM (MIT-licensed, 20k+ GitHub stars, 100+ providers) already
implements ~70% of what we need: OpenAI-compatible API, multi-provider routing, budget enforcement,
usage tracking, PII scrubbing (Presidio), virtual keys, and model name mapping. We deploy LiteLLM
as the gateway and build only the ~30% it doesn't cover: success feedback loop, A/B testing engine,
secret detection, and subscription drain priority logic.

**What LiteLLM gives us for free:**

- **Key isolation:** Virtual keys — app code gets proxy keys, real provider keys stay on LiteLLM
- **Budget enforcement:** Daily/weekly/monthly caps per key, per team, per tag — auto-blocks or reroutes
- **100+ providers:** OpenRouter, Anthropic, Groq, Together, DeepInfra, Fireworks, Azure, HuggingFace, etc.
- **Complexity Router:** Auto-classifies requests into SIMPLE/MEDIUM/COMPLEX/REASONING tiers (zero API calls, sub-ms)
- **PII scrubbing:** Presidio integration, 12+ languages, configurable entity types
- **Model mapping:** Canonical names mapped to provider-specific names (`anthropic/claude-sonnet-4.6` etc.)
- **Usage tracking:** Per-request token counting, cost calculation, spend tracking

**What we build on top:**

- **Success feedback loop** (§20.6) — callers report accuracy, proxy adapts routing
- **A/B testing engine** (§20.7) — split traffic, measure bang-for-buck, auto-promote winners
- **Secret detection** (§20.11) — API key patterns, high-entropy strings (Presidio handles PII but not secrets)
- **Subscription drain priority** (§20.3) — route to Abacus.ai unlimited tier before paid providers
- **Workload-to-stage mapping** (§20.2) — our pipeline taxonomy layered on top of LiteLLM's complexity router

**Tasks:**

1. Deploy LiteLLM proxy via Docker (see Part 20 for full config):
   ```bash
   docker run -d --name litellm-proxy \
     -v /home/jason/code/333Method/config/litellm-config.yaml:/app/config.yaml \
     -p 4000:4000 \
     ghcr.io/berriai/litellm:main-latest \
     --config /app/config.yaml --detailed_debug
   ```
2. Create `config/litellm-config.yaml` with all provider keys and model routing rules
3. Migrate `callLLM()` in `src/utils/llm-provider.js` to be a thin client that:
   - POSTs to `http://localhost:4000/v1/chat/completions`
   - Uses LiteLLM virtual key for auth
   - Passes metadata (stage, siteId, workloadType) via LiteLLM's `metadata` field
4. Remove all API keys from `.env` — move to LiteLLM config (or `.env.secrets`)
5. Remove `MODEL_PRICING`, budget checks, and `logLLMUsage()` from application code — LiteLLM owns this now
6. Build feedback + A/B testing as a thin Node.js sidecar service (§20.6–20.7)
7. Verify all pipeline stages work through proxy; confirm no direct provider calls remain

**Deliverables:**

- LiteLLM proxy running locally, all LLM traffic routed through it
- Zero API keys in application code (key isolation by design)
- Budget enforcement + usage tracking centralised in LiteLLM
- Smart routing operational (complexity-based + cost-optimised)
- Feedback + A/B testing sidecar operational
- Foundation in place for PII scrubbing, distributed workers, and subscription draining

---

</details>

### Phase 0.5: MCP Pre-requisites (Do Now — Before Phase 1)

**Effort: 0.5 hours**

**Tasks:**

1. Enable Cloudflare MCP — credentials available now, no infra setup needed
   - Get API token: dash.cloudflare.com/profile/api-tokens (Workers + R2 permissions)
   - Activate `_TODO_cloudflare` entry in `.mcp.json`
2. Confirm `.mcp.json` TODO stubs for `neon` and `upstash` are ready (already done 2026-02-20)

**Deliverables:**

- Cloudflare MCP active (manage Workers/R2 email tracking from Claude Code)
- `.mcp.json` stubs ready for Neon + Upstash activation in Phases 1-2

---

### Phase 1: Database Migration + MCP Foundation (Weeks 1-5)

**Effort: 50 hours Claude Code, 100 hours Human**

**Tasks:**

1. Set up Neon PostgreSQL instance (4h)
   - After signup: activate `_TODO_neon` entry in `.mcp.json` (5 min)
2. Migrate schema from SQLite to PostgreSQL (10h)
3. Implement dual-write layer (15h)
4. Write data migration script (10h)
5. Test dual-write consistency (6h)
6. Build basic MCP server for agent tools (15h)
   - `agent:create_task`, `agent:get_status`, `agent:view_logs`
   - JSON-RPC 2.0 message handling
   - OAuth 2.1 authentication stub

**Deliverables:**

- Neon PostgreSQL database ($7/month)
- Dual-write middleware
- Data migration script
- Basic MCP server exposing agent tools

### Phase 2: Redis + Distributed Locks (Weeks 6-8)

**Effort: 35 hours Claude Code, 70 hours Human**

**Tasks:**

1. Set up Upstash Redis free tier (2h)
   - After signup: activate `_TODO_upstash` entry in `.mcp.json` (5 min)
2. Implement message bus abstraction (12h)
   - Redis pub/sub for internal agents
   - PostgreSQL LISTEN/NOTIFY fallback
3. Add distributed locks for task claiming (10h)
4. Add heartbeat system (6h)
5. Test failover scenarios (5h)

**Deliverables:**

- Upstash Redis ($0/month - free tier)
- Message bus library (`src/distributed/message-bus.js`)
- Lock manager (`src/distributed/lock-manager.js`)
- Heartbeat monitoring

### Phase 3: WebSocket Server + Mobile Integration (Weeks 9-12)

**Effort: 55 hours Claude Code, 110 hours Human**

**Tasks:**

1. Build WebSocket server (15h)
2. Implement JWT authentication (10h)
3. Create mobile message handlers (12h)
4. Build approval queue system (10h)
5. Create MCP custom connector for Claude Android (8h)
   - Register with Anthropic Connectors Directory
   - Implement OAuth Client ID/Secret flow
   - Document setup for paid plans (Pro/Max/Team/Enterprise)

**Deliverables:**

- WebSocket server (`src/distributed/websocket-server.js`)
- Mobile authentication service
- Approval queue system
- Claude Android MCP custom connector

### Phase 4: OpenClaw + Claude Desktop Integration (Weeks 13-15)

**Effort: 30 hours Claude Code, 60 hours Human**

**Tasks:**

1. Define OpenClaw ↔ MCP protocol (8h)
2. Build plan ingestion via MCP tools (10h)
3. Create task decomposition logic (6h)
4. Build Claude Desktop MCP connector (6h)
   - Dashboard for viewing agent tasks
   - Real-time progress updates
   - Approval interface

**Deliverables:**

- OpenClaw MCP integration
- Plan parser
- Claude Desktop connector for monitoring

### Phase 5: Multi-Node Distribution (Weeks 16-18)

**Effort: 40 hours Claude Code, 80 hours Human**

**Tasks:**

1. Implement machine/instance registration (8h)
2. Modify BaseAgent for distributed claiming (12h)
3. Set up Hetzner 3-node cluster (6h)
   - 1× CX21 (Monitor/Triage/Architect)
   - 2× CCX33 (Developer/QA/Security)
4. Deploy Docker containers across nodes (8h)
5. Test multi-machine task distribution (6h)

**Deliverables:**

- Updated BaseAgent with distributed claiming
- 3-node Hetzner VPS cluster ($109/month)
- Docker deployment configuration

### Phase 6: Production Hardening (Weeks 19-20)

**Effort: 30 hours Claude Code, 60 hours Human**

**Tasks:**

1. Set up monitoring (Prometheus + Grafana) (10h)
2. Implement circuit breakers (8h)
3. Add comprehensive logging (6h)
4. Write runbooks (6h)

**Deliverables:**

- Monitoring dashboards
- Circuit breakers
- Centralized logging
- Operations runbooks

### Total Timeline: 20 weeks (~5 months)

**Total Effort:**

- **Claude Code:** 242–244 hours (~6 weeks of 40h/week)
- **Human Dev:** 480 hours (~12 weeks of 40h/week)

**Total Infrastructure Cost (Monthly):**

- **Phase 0 (Today):** ~~$0 additional — LLM proxy~~ [OBSOLETE] — Claude Max $200/mo flat, no proxy needed (see Part 22)
- **Phase 1-4 (Development):** $24/month (Neon + Hetzner single node)
- **Phase 5+ (Production):** $134/month (Neon + Upstash + Hetzner 3-node cluster)

**First-Year Cost Estimate:**

- Development (4 months @ $24/month): $96
- Production (8 months @ $134/month): $1,072
- **Total Year 1:** $1,168

**Ongoing Cost:** $1,608/year ($134/month)

---

## Part 6: OpenClaw Trust Architecture (Audit Sidecar Pattern)

**Added:** 2026-02-19

The OpenClaw container (Claude Code running on the VPS) requires broad access to configure the
system. The Audit Sidecar Pattern makes this safe: every syscall is captured at kernel level and
streamed to a remote sink OpenClaw cannot reach, creating a tamper-proof audit trail. A
third-party LLM independently reviews this log daily.

Everything below depends on this foundation. Deploy FIRST, before the OpenClaw container starts.

### Host-Level Components (Not in Docker)

**1. auditd rules** — captures all syscalls from OpenClaw's UID (9000):

```bash
# /etc/audit/rules.d/openclaw.rules
-a always,exit -F arch=b64 -S execve -F uid=9000 -k openclaw_exec
-a always,exit -F arch=b64 -S open,openat,creat,truncate -F perm=w -F uid=9000 -k openclaw_writes
-a always,exit -F arch=b64 -S connect -F uid=9000 -k openclaw_network
```

**2. rsyslog forward** — streams to Papertrail immediately (before OpenClaw could tamper):

```
# /etc/rsyslog.d/openclaw-audit.conf
:programname, isequal, "audispd" @@logs.papertrailapp.com:XXXXX
```

**3. git-watcher systemd service** — tracks all file changes on the host and pushes to a private
audit repo:

```bash
# /opt/audit/git-watcher.sh — runs as root, outside Docker
inotifywait -m -r -e modify,create,delete,move /etc /opt/333method | while read event; do
  cd /opt/audit-repo
  git add -A && git commit -m "auto: $event"
  git push origin main  # SSH key exists only on host, not in openclaw container
done
```

**4. iptables isolation** — OpenClaw's UID cannot reach audit sinks:

```bash
iptables -I OUTPUT -m owner --uid-owner 9000 -d <PAPERTRAIL_IP> -j REJECT
iptables -I OUTPUT -m owner --uid-owner 9000 -d <GITHUB_IP_RANGE> -j REJECT
```

---

## Part 7: Containerized VPS Services (Docker Architecture)

All services run in Docker containers on Hetzner CX41 (8 GB RAM, 4 vCPU, 160 GB SSD).

### Three Isolated Networks

- `333method-internal` — PostgreSQL, Redis, pipeline, cron, dashboard (`internal: true`, no internet egress)
- `333method-openclaw-net` — OpenClaw only (isolated from internal services and secrets)
- `333method-proxy-net` — docker-socket-proxy only (bridges both networks, read-only)

### Service Summary

| Service               | Image                         | Networks         | Ports       | Secrets Mounted                       |
| --------------------- | ----------------------------- | ---------------- | ----------- | ------------------------------------- |
| `postgresql`          | postgres:16-alpine            | internal         | none        | postgres_password                     |
| `redis`               | redis:7-alpine                | internal         | none        | redis_password                        |
| `dashboard`           | python:3.12-slim              | internal         | 8501→8501\* | none                                  |
| `pipeline`            | node:20-alpine                | internal         | none        | openrouter, anthropic, resend, twilio |
| `cron`                | node:20-alpine                | internal         | none        | openrouter, anthropic                 |
| `docker-socket-proxy` | tecnativa/docker-socket-proxy | openclaw + proxy | none        | none                                  |
| `openclaw`            | claude-code                   | openclaw         | none        | **NONE**                              |
| `backup`              | restic/restic                 | internal         | none        | backblaze_b2                          |

\*Port 8501 restricted to WireGuard subnet via iptables

### Critical Security Constraints

- `SECRETS: 0` on docker-socket-proxy — OpenClaw cannot enumerate/read Docker Secrets via API
- `POST: 0` on docker-socket-proxy — OpenClaw cannot create/modify containers via Docker API
- `EXEC: 0` on docker-socket-proxy — OpenClaw cannot exec into other containers
- OpenClaw container has **no `/run/secrets` mount** (no `secrets:` stanza in service definition)
- OpenClaw is **NOT** on `333method-internal` — cannot reach PostgreSQL or Redis at all

### MCP Server Configuration for OpenClaw

OpenClaw's Claude Code instance uses MCP servers for structured tool access. Each server is
constrained to the same isolation boundaries as the volume and network rules above. All MCP
stdio processes inside OpenClaw's container inherit UID 9000 — their syscalls are captured by
auditd automatically.

**New service: `mcp-gateway`** — a narrow bridge container (mirrors the docker-socket-proxy
pattern) that hosts network-dependent MCP servers over HTTP/SSE. OpenClaw reaches it over
`333method-openclaw-net`; it reaches PostgreSQL over `333method-internal` with the
`openclaw_readonly` role (sanitized views only).

Updated service table (full):

| Service               | Image                         | Networks            | Ports       | Secrets Mounted                       |
| --------------------- | ----------------------------- | ------------------- | ----------- | ------------------------------------- |
| `postgresql`          | postgres:16-alpine            | internal            | none        | postgres_password                     |
| `redis`               | redis:7-alpine                | internal            | none        | redis_password                        |
| `dashboard`           | python:3.12-slim              | internal            | 8501→8501\* | none                                  |
| `pipeline`            | node:20-alpine                | internal            | none        | openrouter, anthropic, resend, twilio |
| `cron`                | node:20-alpine                | internal            | none        | openrouter, anthropic                 |
| `docker-socket-proxy` | tecnativa/docker-socket-proxy | openclaw + proxy    | none        | none                                  |
| `mcp-gateway`         | node:20-alpine                | openclaw + internal | none        | openclaw_pg_password                  |
| `openclaw`            | claude-code                   | openclaw            | none        | **NONE**                              |
| `backup`              | restic/restic                 | internal            | none        | backblaze_b2                          |

\*Port 8501 restricted to WireGuard subnet via iptables

```nix
# modules/containers.nix — mcp-gateway addition
mcp-gateway = {
  image = "node:20-alpine";
  volumes = [ "/opt/333method:/app:ro" "/run/secrets:/run/secrets:ro" ];
  environment = {
    DATABASE_URL_READONLY = "postgresql://openclaw_readonly:...@postgresql:5432/method333";
    FETCH_ALLOWLIST = "nixos.org,wiki.nixos.org,man7.org,netbird.io,docs.docker.com";
    MCP_PORT = "3000";
  };
  extraOptions = [
    "--network=333method-openclaw-net"
    "--network=333method-internal"   # only service besides socket-proxy that bridges networks
  ];
  cmd = [ "node" "/app/scripts/mcp-gateway.js" ];
};
```

**OpenClaw's `~/.claude.json` MCP config** (built into the `claude-code` Docker image):

```json
{
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": [
        "-y",
        "@modelcontextprotocol/server-filesystem",
        "/workspace",
        "/app/src:readonly",
        "/app/tests:readonly",
        "/app/docs:readonly"
      ]
    },
    "memory": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-memory"]
    },
    "docker": {
      "command": "npx",
      "args": ["-y", "mcp-server-docker"],
      "env": { "DOCKER_HOST": "tcp://docker-socket-proxy:2375" }
    },
    "gateway": {
      "url": "http://mcp-gateway:3000/sse",
      "type": "sse"
    }
  }
}
```

`filesystem` and `memory` run as stdio subprocesses inside the OpenClaw container — their file
operations are captured by auditd. `docker` also runs stdio, inheriting the socket proxy's
`POST:0 / SECRETS:0 / EXEC:0` restrictions. `gateway` connects via HTTP/SSE to the
`mcp-gateway` sidecar which applies its own database-role and domain-allowlist restrictions.

**What OpenClaw canNOT do via MCP (by design):**

- Access `/run/secrets`, `.env`, `logs/`, `screenshots/`, `db/sites.db` — not in any filesystem mount
- Reach Papertrail or GitHub audit repos — iptables UID-9000 block applies to all subprocess
  connections, MCP or otherwise
- Read Docker Secrets via the gateway API — `SECRETS:0` enforced at the proxy before the request
  reaches any MCP logic
- Query raw `sites`, `outreaches`, or `conversations` tables — `openclaw_readonly` role can only
  access the three sanitized views (migration 064)

---

## Part 8: Quantum-Safe VPN (NetBird + Rosenpass)

**Recommendation: NetBird + Rosenpass** (hybrid post-quantum + classical WireGuard)

- NetBird is WireGuard-based with self-hostable management plane
- Since v0.25.4 it embeds Rosenpass (ML-KEM + Classic McEliece pre-shared key rotation)
- Hybrid security: attacker must break BOTH classical Curve25519 AND PQ layers simultaneously
- NixOS module available: `services.netbird.enable = true`
- Kernel 6.12.69 supports WireGuard natively; Rosenpass runs in userspace atop it

OpenClaw bootstrap task `setup_wireguard` writes a NixOS config fragment to
`/etc/nixos/netbird.nix` with `{{NETBIRD_SETUP_KEY}}` placeholder. The secrets-init service
(not OpenClaw) fills in the real setup key before `nixos-rebuild switch` runs.

After VPN is up, restrict dashboard to WireGuard subnet only:

```bash
iptables -I INPUT -p tcp --dport 8501 -s 100.64.0.0/10 -j ACCEPT
iptables -A INPUT -p tcp --dport 8501 -j DROP
```

---

## Part 9: Secrets Management (OpenClaw-Blind)

### Docker Secrets + `scripts/load-secrets-to-env.js` Adapter

Docker Secrets mount as files at `/run/secrets/<name>` with 0400 permissions. auditd logs file
paths on open, NOT file contents — so secrets never appear in audit logs. OpenClaw's container
has no `secrets:` stanza, so it has zero access.

**Secrets created once by human operator via SSH (not by OpenClaw):**

```bash
docker secret create postgres_password   <(echo -n "$POSTGRES_PASSWORD")
docker secret create redis_password      <(echo -n "$REDIS_PASSWORD")
docker secret create openrouter_api_key  <(echo -n "$OPENROUTER_API_KEY")
docker secret create anthropic_api_key   <(echo -n "$ANTHROPIC_API_KEY")
docker secret create resend_api_key      <(echo -n "$RESEND_API_KEY")
docker secret create twilio_credentials  <(echo -n "$SID:$TOKEN:$PHONE")
docker secret create backblaze_b2_creds  <(echo -n "$B2_ID:$B2_KEY:$B2_BUCKET")
docker secret create netbird_setup_key   <(echo -n "$NETBIRD_KEY")
```

**New file `scripts/load-secrets-to-env.js`** — runs at container startup, reads
`/run/secrets/*` files, populates `process.env`. Falls back to `.env` silently in dev mode.
Never logs secret values — only logs which keys were loaded.

### OpenClaw Template Contract

OpenClaw writes config files using `{{PLACEHOLDER}}` syntax only. A `validateNoSecrets()` guard
in `src/agents/openclaw-bootstrap.js` throws if content matches real-secret patterns (API key
regex, long base64 strings). A separate privileged init service (not Docker, not OpenClaw)
substitutes real values into templates at deploy time.

### On NixOS: sops-nix (Preferred Over Docker Secrets)

For NixOS deployments, use `sops-nix` + `age` encryption instead:

- Secrets committed to git **encrypted**, decryptable only by the server's SSH host key
- `nixos-rebuild switch` decrypts and places at `/run/secrets/<name>` (0400 perms)
- OpenClaw has no access: not in the `sops` group, container has no `/run/secrets` mount
- Cloning to new server = update `.sops.yaml` with new server's age public key

```bash
# Convert server SSH host key to age key (run once on production server):
nix-shell -p ssh-to-age --run 'cat /etc/ssh/ssh_host_ed25519_key.pub | ssh-to-age'
```

### Current Status (as of 2026-03-01) — Already Implemented

The sops-nix + age pipeline is **live** in `333Method-infra/`:

| Component          | Location                                   | Status                       |
| ------------------ | ------------------------------------------ | ---------------------------- |
| Encrypted secrets  | `333Method-infra/secrets/production.yaml`  | ✅ 35 secrets encrypted      |
| SOPS config        | `333Method-infra/.sops.yaml`               | ✅ age key from VPS SSH host |
| Secret injection   | `333Method/scripts/load-secrets-to-env.js` | ✅ reads `/run/secrets/*`    |
| NixOS declarations | `333Method-infra/modules/secrets.nix`      | ✅ 35 secrets declared       |

**`.env` file convention** (three-file split from commit `759a2e9b`):

| File                   | Contents                                                    | Commit?                     |
| ---------------------- | ----------------------------------------------------------- | --------------------------- |
| `.env`                 | Non-secret runtime config (URLs, flags, limits)             | ✅ example only             |
| `.env.secrets`         | API keys, tokens, passwords                                 | ❌ never commit real values |
| `.env.agents`          | Agent system tuning parameters                              | ✅ example only             |
| `.env.secrets.example` | Documents what secrets exist, with `CHANGE_ME` placeholders | ✅ safe to commit           |

Production values live in `333Method-infra/secrets/production.yaml` (SOPS-encrypted). The `scripts/load-secrets-to-env.js` loader reads `/run/secrets/*` files at container startup and injects into `process.env`.

**Workflow for adding a new secret:**

1. Add placeholder to `.env.secrets.example`
2. Add real value to local `.env.secrets` (or `.env` for dev convenience)
3. Add to `333Method-infra/secrets/production.yaml` via `SOPS_AGE_KEY_FILE=~/.age/infra.key sops secrets/production.yaml`
4. Add declaration to `333Method-infra/modules/secrets.nix`

> **Note:** Do NOT add secrets to `.env.example` — that file is for non-secret config only.

---

## Part 10: OpenClaw Bootstrap Agent

### New file: `src/agents/openclaw-bootstrap.js`

Handles VPS setup tasks via the existing `agent_tasks` table (`assigned_to: 'openclaw'`):

| Task Type               | What OpenClaw Does                                                 | Secret Placeholders      |
| ----------------------- | ------------------------------------------------------------------ | ------------------------ |
| `setup_wireguard`       | Write NetBird/NixOS config template                                | `NETBIRD_SETUP_KEY`      |
| `harden_ssh`            | Write sshd_config (disable passwords, modern ciphers, rate limits) | none                     |
| `setup_docker_compose`  | Write `docker-compose.yml` with `{{SECRET}}` placeholders          | all API keys             |
| `initialize_postgresql` | Write init SQL, create databases + extensions                      | `POSTGRES_ROLE_PASSWORD` |
| `configure_redis`       | Write `redis.conf.tmpl` with requirepass placeholder               | `REDIS_PASSWORD`         |
| `setup_backup`          | Write `scripts/backup.sh` with B2 endpoint + bucket                | `B2_ACCOUNT_ID/KEY`      |

All output paths validated against `ALLOWED_PREFIXES = ['/opt/333method/', '/workspace/', '/templates/']`.
Bootstrap tasks are **created by the human operator** — OpenClaw processes and produces templates,
which the human reviews in the git-watcher GitHub repo before secrets-init fills them.

### MCP Tools Available During Bootstrap Tasks

OpenClaw's MCP servers (configured in Part 7) give it structured access to everything needed for
bootstrap tasks without requiring direct filesystem or network access beyond its workspace:

| MCP Server               | Transport | Tools Used                                              | Bootstrap Task                                                       |
| ------------------------ | --------- | ------------------------------------------------------- | -------------------------------------------------------------------- |
| `filesystem`             | stdio     | `read_file`, `write_file`, `list_directory`             | All tasks — write config templates to `/workspace`                   |
| `filesystem` (ro)        | stdio     | `read_file`                                             | Read existing `src/` configs as reference patterns                   |
| `fetch` (via gateway)    | SSE       | `fetch`                                                 | Look up current SSH cipher lists, NixOS option docs, NetBird API     |
| `docker`                 | stdio     | `list_containers`, `inspect_container`, `list_networks` | `setup_docker_compose` — verify existing container state             |
| `postgres` (via gateway) | SSE       | `query`                                                 | Check `openclaw_v_agent_tasks` — own tasks only (Row Level Security) |
| `memory`                 | stdio     | `set`, `get`, `list`                                    | Track bootstrap progress across multi-step task runs                 |

**Fetch allowlist** (enforced in `mcp-gateway.js`):
`nixos.org`, `wiki.nixos.org`, `man7.org` (Linux man pages), `netbird.io/docs`,
`docs.docker.com`, `redis.io/docs`, `postgresql.org/docs`

OpenClaw cannot fetch Papertrail, GitHub audit repos, or any IP in the Papertrail/GitHub ranges
(iptables UID-9000 block applies to all subprocess network calls, MCP fetch included).

---

## Part 11: AI Audit Review Pipeline

### New file: `src/cron/audit-log-review.js`

Daily flow:

1. Fetch last 24h of OpenClaw audit logs from Papertrail Search API (paginated, up to 20,000 events)
2. Truncate to 80,000 chars if needed
3. POST to OpenRouter using `AUDIT_REVIEW_MODEL` (default: `openai/gpt-4o` — NOT Anthropic, for independence)
4. Parse JSON response: `{ risk_level, summary, suspicious_behaviors[], flagged_commands[], recommendation }`
5. Store in `audit_reviews` table
6. If `risk_level >= 3`: call `human-review-queue.js` `addReviewItem()` with `type='security'`
7. If `risk_level >= 4`: `priority='critical'`

**Review prompt flags:**

- Access to `/run/secrets`, `/proc/*/environ`
- Modifications to audit config (rsyslog.conf, auditd.conf, audit log files)
- Unexpected outbound connections to non-whitelisted IPs
- Privilege escalation (su, sudo, setuid binaries)
- Writes outside `/workspace/` or `/templates/`
- Docker socket direct access (bypassing proxy)

**Risk level scale:** 1=Routine, 2=Minor anomaly, 3=Human review required, 4=High risk (priority), 5=Critical

### Register in `src/cron.js`:

```javascript
auditLogReview: async () => {
  const { default: run } = await import('./cron/audit-log-review.js');
  return run();
},
```

### New DB migration: `db/migrations/062-create-audit-reviews.sql`

```sql
CREATE TABLE IF NOT EXISTS audit_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  review_date TEXT NOT NULL DEFAULT (datetime('now')),
  period_start TEXT NOT NULL,
  period_end TEXT NOT NULL,
  risk_level INTEGER NOT NULL CHECK(risk_level BETWEEN 1 AND 5),
  model_used TEXT NOT NULL,
  summary TEXT NOT NULL,
  suspicious_behaviors_json TEXT,
  flagged_commands_json TEXT,
  recommendation TEXT,
  raw_response TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_audit_reviews_date ON audit_reviews(review_date DESC);
CREATE INDEX idx_audit_reviews_risk ON audit_reviews(risk_level);
```

### New DB migration: `db/migrations/063-seed-audit-review-cron.sql`

Seeds `cron_jobs` row for `auditLogReview`, schedule: 1 day, initially disabled until
`PAPERTRAIL_API_TOKEN` is configured.

### Dashboard Integration

**`dashboard/utils/database.py`** — add two new cached query functions:

- `get_recent_audit_reviews(days=7)` → DataFrame with risk levels, summaries, JSON blobs
- `get_audit_review_trend()` → DataFrame for 30-day risk level sparkline

**`dashboard/pages/6_🖥️_System_Health.py`** — append "🔍 AI Audit Log Reviews" section:

- 4 metric tiles: Latest Risk Level, 7-Day Avg, 7-Day Peak, Reviews Complete
- Plotly line chart: 30-day risk trend, horizontal threshold at 3
- Expandable review cards with suspicious behaviors and flagged commands

**`dashboard/pages/8_🤝_Human_Review.py`** — add "🔍 AI Audit Review Alerts" section at top:

- Queries `human_review_queue WHERE type='security' AND file LIKE 'audit_reviews#%'`
- Reviewed / False Positive action buttons
- Links to System Health page for full detail

### GitHub MCP Integration (Out-of-Band Alert Channel)

The `audit-log-review.js` cron job uses the **GitHub MCP server** (running in the `pipeline` or
`cron` container — never in OpenClaw's container) to create issues in the private audit repo when
`risk_level >= 4`. This provides an alert channel that works even if the Streamlit dashboard is
unreachable:

```javascript
// audit-log-review.js — risk_level >= 4 path
import { McpClient } from '@modelcontextprotocol/sdk/client/index.js';

// GitHub MCP creates issue in 333Method-audit repo (private)
await githubMcp.callTool('create_issue', {
  owner: process.env.AUDIT_GITHUB_OWNER,
  repo: process.env.AUDIT_GITHUB_REPO,
  title: `CRITICAL: OpenClaw anomaly detected [${reviewDate}]`,
  body: formatAuditIssueBody(review), // risk_level, flagged_commands[], git-watcher commit link
  labels: ['security', 'openclaw', review.risk_level >= 5 ? 'critical' : 'high'],
});
```

The issue body links directly to the git-watcher commit that captured the suspicious file change,
giving the human reviewer a one-click path from GitHub notification → audit diff.

**GitHub MCP config** (in `pipeline`/`cron` container, not OpenClaw):

```json
{
  "mcpServers": {
    "github": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-github"],
      "env": { "GITHUB_PERSONAL_ACCESS_TOKEN": "${AUDIT_GITHUB_TOKEN}" }
    }
  }
}
```

### New Environment Variables (add to `.env.example`):

```bash
# Audit Log Review
PAPERTRAIL_API_TOKEN=             # Papertrail API token for log fetching
PAPERTRAIL_OPENCLAW_SYSTEM_ID=    # Filter to openclaw container logs only
AUDIT_REVIEW_MODEL=openai/gpt-4o  # Non-Anthropic for independence (or x-ai/grok-2)

# Audit GitHub repo (for out-of-band issue alerts on risk_level >= 4)
AUDIT_GITHUB_TOKEN=               # PAT with issues:write on audit repo only
AUDIT_GITHUB_OWNER=               # GitHub org/user owning the audit repo
AUDIT_GITHUB_REPO=333Method-audit # Private audit repo name

# NetBird VPN
NETBIRD_MANAGEMENT_URL=https://api.netbird.io

# Backblaze B2 Backup
B2_BUCKET=method333-backups-prod
B2_ENDPOINT=https://s3.us-west-004.backblazeb2.com
# B2_ACCOUNT_ID and B2_ACCOUNT_KEY injected via Docker Secrets / sops-nix only
```

---

## Part 12: NixOS Infrastructure-as-Code + Server Cloning

### VPS Provider: Hostinger (Pre-Paid, Confirmed 2026-02-23)

> **Update 2026-02-23:** Hostinger KVM 1 is pre-paid for a year — Hetzner migration is deferred.
> Use **nixos-infect** (not nixos-anywhere) and `/dev/vda` in disko.nix. See Part K for details.

The original analysis favoured Hetzner for official NixOS support. That remains true for future
upgrades, but the current VPS is Hostinger KVM 1 (4 GB RAM, 1 vCPU, pre-paid $131.88/year).

**Hostinger deployment path:**

- **nixos-infect** converts the running Ubuntu VPS to NixOS without rescue mode or kexec:
  ```bash
  curl https://raw.githubusercontent.com/elitak/nixos-infect/master/nixos-infect | \
    NIX_CHANNEL=nixos-24.11 bash 2>&1 | tee /tmp/infect.log
  ```
- **`disko.nix`**: use `device = "/dev/vda"` (Hostinger VirtIO), not `/dev/sda` (Hetzner)
- **After year**: migrate to Hetzner via `nixos-anywhere` + `pg_dump` → `pg_restore`

| Feature                | Hetzner Cloud                        | Hostinger (current)              |
| ---------------------- | ------------------------------------ | -------------------------------- |
| Official NixOS image   | ✅ Yes (marketplace)                 | ❌ No — use nixos-infect         |
| nixos-anywhere support | ✅ Official docs + community reports | ⚠️ kexec unreliable — use infect |
| Custom ISO upload      | ✅ Yes                               | ❌ No                            |
| Pricing (pre-paid)     | €15.23/mo (~$16.50)                  | $131.88/year (~$11/mo) ✅ paid   |

### Separate `333Method-infra/` Repository

Infrastructure-as-code lives in a separate private git repo (not the app repo):

```
333Method-infra/
  flake.nix                         # entry point, pins all dependency versions
  flake.lock                        # version lockfile (git-tracked, reproducible)
  .sops.yaml                        # sops encryption config (git-tracked)
  disko.nix                         # disk partition layout (for nixos-anywhere)
  modules/
    containers.nix                  # virtualisation.oci-containers for all services
    monitoring.nix                  # auditd, rsyslog→Papertrail, git-watcher, NetBird
    security.nix                    # SSH hardening, firewall, iptables, OpenClaw isolation
    backup.nix                      # restic + Backblaze B2 systemd timer
    secrets.nix                     # sops-nix secret declarations
  hosts/
    production/
      configuration.nix             # host-specific overrides
  secrets/
    production.yaml                 # ALL secrets encrypted (safe to commit to git)
```

### NixOS Flakes — Reproducibility

`flake.nix` pins exact nixpkgs commit → same config = identical system on every server:

```nix
{
  description = "333Method VPS Infrastructure";
  inputs = {
    nixpkgs.url      = "github:nixos/nixpkgs/nixos-24.11";
    disko.url        = "github:nix-community/disko";
    disko.inputs.nixpkgs.follows = "nixpkgs";
    sops-nix.url     = "github:Mic92/sops-nix";
    sops-nix.inputs.nixpkgs.follows = "nixpkgs";
  };
  outputs = { self, nixpkgs, disko, sops-nix }: {
    nixosConfigurations.production = nixpkgs.lib.nixosSystem {
      system = "x86_64-linux";
      modules = [
        disko.nixosModules.disko
        sops-nix.nixosModules.sops
        ./hosts/production/configuration.nix
        ./modules/containers.nix
        ./modules/monitoring.nix
        ./modules/security.nix
        ./modules/backup.nix
        ./modules/secrets.nix
      ];
    };
  };
}
```

### `virtualisation.oci-containers` (NixOS Native) vs Docker Compose

**Use `virtualisation.oci-containers`, not Docker Compose.** Each container becomes a systemd
unit with atomic rollbacks. `compose2nix` converts an existing `docker-compose.yml` automatically.
Docker Compose is redundant on NixOS.

```nix
# modules/containers.nix (excerpt)
virtualisation.docker.enable = true;
virtualisation.oci-containers.backend = "docker";
virtualisation.oci-containers.containers = {
  postgresql = {
    image = "postgres:16-alpine";
    volumes = [ "postgres_data:/var/lib/postgresql/data" "/run/secrets:/run/secrets:ro" ];
    environment = { POSTGRES_DB = "method333"; POSTGRES_PASSWORD_FILE = "/run/secrets/postgres_password"; };
    extraOptions = [ "--network=333method-internal" ];
  };
  openclaw = {
    image = "claude-code:latest";
    volumes = [ "/opt/333method-openclaw-workspace:/workspace" ];
    environment.DOCKER_HOST = "tcp://docker-socket-proxy:2375";
    extraOptions = [ "--network=333method-openclaw-net" "--user=9000:9000" ];
    # NO /run/secrets mount
  };
  # ... redis, dashboard, pipeline, cron, docker-socket-proxy, backup
};
```

### Audit Sidecar as NixOS Module (`modules/monitoring.nix`)

```nix
{ config, pkgs, lib, ... }:
{
  security.auditd.enable = true;
  services.auditd.extraConfig = ''
    -a always,exit -F arch=b64 -S execve -F uid=9000 -k openclaw_exec
    -a always,exit -F arch=b64 -S openat,creat -F perm=w -F uid=9000 -k openclaw_writes
    -a always,exit -F arch=b64 -S connect -F uid=9000 -k openclaw_network
  '';
  services.rsyslog = {
    enable = true;
    extraConfig = lib.mkAfter '':programname, isequal, "audispd" @@logs.papertrailapp.com:XXXXX'';
  };
  systemd.services.git-watcher = {
    description = "333Method File Change Audit Watcher";
    after = [ "network.target" ];
    wantedBy = [ "multi-user.target" ];
    path = with pkgs; [ git inotify-tools openssh ];
    serviceConfig.ExecStart = "${pkgs.bash}/bin/bash /opt/audit/git-watcher.sh";
  };
  networking.firewall.extraCommands = ''
    iptables -I OUTPUT -m owner --uid-owner 9000 -d <PAPERTRAIL_IP> -j REJECT
  '';
}
```

### SSH Hardening as NixOS Module (`modules/security.nix`)

```nix
services.openssh = {
  enable = true;
  settings = {
    PasswordAuthentication = false;
    PermitRootLogin = "no";
    MaxAuthTries = 3;
    LoginGraceTime = 20;
    KexAlgorithms = [ "curve25519-sha256" "diffie-hellman-group16-sha512" ];
    Ciphers = [ "chacha20-poly1305@openssh.com" "aes256-gcm@openssh.com" ];
  };
};
users.users.openclaw = { uid = 9000; isSystemUser = true; group = "openclaw"; };
```

### Cloning to a New Server (`nixos-anywhere`)

```bash
# From your local NixOS dev machine — one command converts any Ubuntu/Debian VPS to NixOS:
nix run nixpkgs#nixos-anywhere -- --flake .#production root@new-server-ip
# nixos-anywhere: SSHs in → kexec → disko partitions disk → installs NixOS → reboots
```

After reboot, add the new server's age key to `.sops.yaml` and re-encrypt:

```bash
# Get new server's age public key:
ssh-keyscan new-server-ip | ssh-to-age
# Update .sops.yaml, then:
sops updatekeys secrets/production.yaml
nixos-rebuild switch --flake .#production
```

**Data migration** (separate from OS):

- PostgreSQL: `pg_dump` → copy → `pg_restore`
- SQLite: `rsync db/sites.db`
- Screenshots: `rsync screenshots/`
- Config templates: already in git

### OpenClaw's Role in NixOS IaC

OpenClaw writes config **proposals** to `/opt/333method-openclaw-workspace/`. The git-watcher
captures every file change. A human reviews the diff in GitHub, merges into the flake modules,
then runs `nixos-rebuild switch --flake .#production`. OpenClaw never directly applies system
state — the declarative NixOS flake is always the source of truth.

---

## Part 13: Fine-Grained Access Control (Files + Database Tables)

### Baseline: OpenClaw Is Already Fully Isolated

With the network architecture in Part 7, OpenClaw has **zero** access by default to:

- PostgreSQL / Redis (different Docker network)
- `postgres_data`, `redis_data`, `app_data`, `screenshot_data` volumes (not mounted)
- `/run/secrets` (no `secrets:` stanza in openclaw service)
- Other containers' filesystems (`EXEC: 0` on docker-socket-proxy)
- `db/sites.db` SQLite file (not mounted in openclaw container)

Network-level denial is the strongest possible control. Table-level grants are moot by default.

### If OpenClaw Needs Partial DB Access (Monitoring/Debugging)

#### PostgreSQL: Sanitized Views Only

```sql
CREATE ROLE openclaw_readonly NOLOGIN NOINHERIT;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM openclaw_readonly;

-- Expose ONLY sanitized views — never raw tables
CREATE VIEW openclaw_v_agent_tasks AS
  SELECT id, task_type, assigned_to, status, priority, retry_count, created_at, updated_at
  FROM agent_tasks;
  -- EXCLUDED: context_json, result_json (may contain API responses)

CREATE VIEW openclaw_v_pipeline_status AS
  SELECT status, COUNT(*) AS count FROM sites GROUP BY status;
  -- EXCLUDED: domain, contacts_json, score_json, html_dom

CREATE VIEW openclaw_v_error_summary AS
  SELECT SUBSTR(error_message, 1, 80) AS error_prefix, COUNT(*) AS count
  FROM sites WHERE error_message IS NOT NULL
  GROUP BY SUBSTR(error_message, 1, 80);
  -- EXCLUDED: actual URLs, customer contact data

GRANT SELECT ON openclaw_v_agent_tasks     TO openclaw_readonly;
GRANT SELECT ON openclaw_v_pipeline_status TO openclaw_readonly;
GRANT SELECT ON openclaw_v_error_summary   TO openclaw_readonly;

-- NEVER GRANT (even view access):
-- outreaches, conversations, sites (raw), openrouter_credit_log
```

#### Row Level Security: OpenClaw Sees Only Its Own Tasks

```sql
ALTER TABLE agent_tasks ENABLE ROW LEVEL SECURITY;
CREATE POLICY openclaw_own_tasks ON agent_tasks
  FOR SELECT TO openclaw_readonly
  USING (assigned_to = 'openclaw');
```

#### pgBouncer Query Logging

Route OpenClaw's DB connection through pgBouncer with `LOG_QUERIES=1` — every SQL query
appears in the audit trail via rsyslog → Papertrail.

### File Access Control: Volume Mount Restrictions

```nix
# modules/containers.nix — openclaw volumes
openclaw.volumes = [
  "/opt/333method-openclaw-workspace:/workspace"   # read/write workspace
  "/opt/333method/src:/app/src:ro"                 # source code, read-only
  "/opt/333method/tests:/app/tests:ro"             # tests, read-only
  "/opt/333method/docs:/app/docs:ro"               # docs, read-only
  # NOT MOUNTED: db/, .env, logs/, screenshots/, /run/secrets/
];
```

### AppArmor Profile (Defence-in-Depth)

```nix
security.apparmor.policies."docker-openclaw".profile = ''
  #include <abstractions/base>
  /opt/333method-openclaw-workspace/** rwk,
  /opt/333method/src/**  r,
  /opt/333method/tests/** r,
  /opt/333method/docs/**  r,
  deny /opt/333method/db/**          rwx,
  deny /opt/333method/.env           rwx,
  deny /run/secrets/**               rwx,
  deny /opt/333method/logs/**        r,
  deny /opt/333method/screenshots/** r,
'';
```

### Access Control Summary

| Resource                               | OpenClaw Access        | Mechanism                      |
| -------------------------------------- | ---------------------- | ------------------------------ |
| PostgreSQL (any table)                 | ❌                     | Different Docker network       |
| Redis                                  | ❌                     | Different Docker network       |
| `sites`, `outreaches`, `conversations` | ❌                     | Network isolation              |
| `openrouter_credit_log`                | ❌                     | Network isolation              |
| `/run/secrets`                         | ❌                     | No secrets mount in container  |
| `db/sites.db`                          | ❌                     | Not mounted                    |
| `screenshots/`, `logs/`, `.env`        | ❌                     | Not mounted + AppArmor deny    |
| Docker exec into other containers      | ❌                     | `EXEC: 0` on socket proxy      |
| Docker Secrets API                     | ❌                     | `SECRETS: 0` on socket proxy   |
| App source code (`src/`)               | ✅ read-only           | Explicit `:ro` mount           |
| App tests / docs                       | ✅ read-only           | Explicit `:ro` mount           |
| Its own workspace                      | ✅ read/write          | `/workspace` mount             |
| Agent task queue (if enabled)          | ✅ via sanitized view  | `openclaw_v_agent_tasks` + RLS |
| Pipeline status (if enabled)           | ✅ via aggregated view | `openclaw_v_pipeline_status`   |
| Docker container status                | ✅ read-only           | docker-socket-proxy            |

### New Migration: `db/migrations/064-openclaw-readonly-views.sql`

Creates the three sanitized views (SQLite-compatible syntax, no PII exposure). PostgreSQL role
creation is handled during initial server setup, outside the app migration system.

---

## Part 14: MCP Services for VPS Trust Architecture

**Added:** 2026-02-19

MCP servers are the structured tool layer that gives OpenClaw (and the main pipeline/cron
containers) access to exactly what they need — no more. The key security principle: **MCP servers
inherit the same isolation boundaries as Docker volumes and networks**. A filesystem MCP server
that isn't given a path cannot expose that path. A postgres MCP using `openclaw_readonly` can
only see the sanitized views. iptables UID-9000 rules block MCP subprocess network calls to
audit sinks the same as any other network call from that UID.

### MCP Servers by Container

#### OpenClaw Container (claude-code image)

| MCP Server   | Package                                   | Transport | Purpose                                                            |
| ------------ | ----------------------------------------- | --------- | ------------------------------------------------------------------ |
| `filesystem` | `@modelcontextprotocol/server-filesystem` | stdio     | Scoped file R/W: `/workspace` (rw), `src/tests/docs` (ro)          |
| `memory`     | `@modelcontextprotocol/server-memory`     | stdio     | Persistent KV across task runs                                     |
| `docker`     | `mcp-server-docker`                       | stdio     | Container inspect via socket-proxy (`POST:0 / SECRETS:0 / EXEC:0`) |
| `gateway`    | custom (`scripts/mcp-gateway.js`)         | HTTP/SSE  | Proxied postgres + fetch (see mcp-gateway container)               |

All stdio servers run as UID 9000 — every file open, network connect, and exec is captured by
auditd. The gateway SSE transport also goes through UID-9000 network path, captured by the
`connect` syscall audit rule.

#### mcp-gateway Container (node:20-alpine, bridges openclaw-net → internal)

| Tool Exposed     | Source                             | Restriction                                                                                                 |
| ---------------- | ---------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `postgres/query` | PostgreSQL via `openclaw_readonly` | Sanitized views only; RLS limits to own tasks                                                               |
| `fetch`          | HTTP egress                        | Domain allowlist: nixos.org, man7.org, netbird.io/docs, docs.docker.com, redis.io/docs, postgresql.org/docs |

The gateway is the **only non-proxy service** that bridges `333method-openclaw-net` and
`333method-internal`. Like docker-socket-proxy, it applies restrictions before forwarding —
OpenClaw cannot bypass them even if it sends raw SQL, because the gateway uses a read-only
role connection that the database enforces independently.

#### pipeline / cron Containers (internal network only)

| MCP Server | Package                               | Purpose                                                                         |
| ---------- | ------------------------------------- | ------------------------------------------------------------------------------- |
| `github`   | `@modelcontextprotocol/server-github` | Create audit issues in 333Method-audit repo when risk_level ≥ 4                 |
| `fetch`    | `@modelcontextprotocol/server-fetch`  | Papertrail Search API for audit log fetching (unrestricted — internal net only) |

These containers are on `333method-internal` with no connection to `333method-openclaw-net`.
OpenClaw cannot reach them or their MCP servers.

### Security Constraint Table

| Resource                                                 | OpenClaw MCP Access | Mechanism                                                  |
| -------------------------------------------------------- | ------------------- | ---------------------------------------------------------- |
| `/workspace/**`                                          | ✅ read/write       | filesystem MCP — explicitly listed                         |
| `src/`, `tests/`, `docs/`                                | ✅ read-only        | filesystem MCP — `:readonly` flag                          |
| `db/sites.db`, `.env`, `logs/`, `screenshots/`           | ❌                  | Not listed in any filesystem MCP mount                     |
| `/run/secrets/**`                                        | ❌                  | AppArmor deny + not mounted in container                   |
| PostgreSQL sanitized views                               | ✅ via gateway      | `openclaw_readonly` role + gateway proxy                   |
| PostgreSQL raw tables (sites, outreaches, conversations) | ❌                  | Role has no grants on raw tables                           |
| Docker container list/inspect                            | ✅ read-only        | docker MCP + socket-proxy (`POST:0`)                       |
| Docker exec / create / secrets                           | ❌                  | socket-proxy (`EXEC:0 / POST:0 / SECRETS:0`)               |
| Papertrail API                                           | ❌                  | iptables UID-9000 block + not in fetch allowlist           |
| GitHub audit repo                                        | ❌                  | iptables UID-9000 block + not in fetch allowlist           |
| Docs / man pages / NixOS options                         | ✅                  | fetch MCP via gateway allowlist                            |
| GitHub issue creation (audit alerts)                     | ✅ pipeline only    | github MCP in pipeline/cron — OpenClaw has no path to this |

### Priority Order for Implementation

1. **`filesystem` MCP** (scoped) — enables all bootstrap config-writing tasks immediately
2. **`mcp-gateway`** + postgres MCP — enables task queue visibility and status reads
3. **`docker` MCP** — enables container inspection during `setup_docker_compose` bootstrap task
4. **`github` MCP** (pipeline container) — enables out-of-band audit alerts on risk_level ≥ 4
5. **`fetch` MCP** (via gateway, allowlisted) — enables config research during bootstrap tasks
6. **`memory` MCP** — enables bootstrap state tracking across multi-run tasks

### Key Rules

- **Never give OpenClaw a fetch MCP with Papertrail or GitHub audit repo in the allowlist.** The
  iptables UID-9000 block is the backstop, but allowlist is the first line of defense.
- **Never mount MCP servers that have access to secrets in OpenClaw's container.** The gateway
  reads `openclaw_pg_password` from `/run/secrets` to build its connection string, but that
  secret is not exposed to OpenClaw through any MCP tool output.
- **Rotate `AUDIT_REVIEW_MODEL` monthly** between `openai/gpt-4o` and `x-ai/grok-2` to avoid
  a single AI vendor reviewing Claude's behavior. Independence is the point.
- **All MCP server configs are version-controlled** in the `333Method-infra/` flake — changes to
  what tools OpenClaw has access to appear in the git-watcher audit trail as NixOS config diffs.

### Cross-Reference with Part 2

Part 2 covers MCP for the **distributed agent coordination system** (Redis pub/sub, Claude Desktop
integration, mobile approvals). Part 14 covers MCP for the **VPS trust architecture** (OpenClaw
isolation, audit pipeline, bootstrap tasks). The two are complementary: Part 2's agent MCP server
exposes `agent:create_task` / `agent:approve_action` tools used by human operators; Part 14's MCP
servers expose filesystem/database/docker tools used by OpenClaw itself.

---

## Part 15: AI-Driven Penetration Testing Integration

**Added:** 2026-02-21

Three complementary tools covering different attack surfaces — no single tool covers everything:

| Tool        | Type     | Layer                                          | Validated                                    |
| ----------- | -------- | ---------------------------------------------- | -------------------------------------------- |
| **Shannon** | Whitebox | App source code → guided exploitation          | ✅ 96.15% XBOW Benchmark                     |
| **Strix**   | Blackbox | App at runtime (CI/CD, dynamic PoC)            | ✅ vulnbank.org (SQLi, auth bypass, IDOR)    |
| **CAI**     | Blackbox | Network/VPS perimeter (external attacker view) | ✅ 99.04% percentile across 5 CTFs, $50K win |

### Shannon — Whitebox CI Integration

Reads source code and uses it to direct the exploit strategy against the running app. Most
valuable for the Node.js codebase: reasons about actual data flows — ZenRows proxy calls
(SSRF), raw SQLite query paths (SQLi), inbound Twilio webhook handler (auth bypass).

- **Architecture:** Claude Agent SDK orchestrator → Recon → Code Analysis → Dynamic Testing → Report
- **Coverage:** SQLi, XSS, SSRF, Auth bypass (OWASP core)
- **License:** AGPL-3.0 Lite / Pro enterprise
- **Trigger:** Weekly (Monday 2am) against test environment with source code mounted

### Strix — Blackbox Dynamic CI Testing

"Graph of Agents" — parallel specialist agents with dynamic coordination. Validates with real
PoC exploits. Natively supports Claude Sonnet 4.6.

- **Coverage:** IDOR, privilege escalation, auth bypass, SQLi/NoSQLi/command injection, SSRF,
  XXE, deserialization, XSS, prototype pollution, JWT, session management
- **Models:** Claude Sonnet 4.6, GPT-5, Gemini 3 Pro, Ollama, Bedrock, Azure
- **License:** Apache 2.0
- **Trigger:** Nightly (3am) against staging environment

### CAI (Alias Robotics) — Blackbox External Perimeter

Most CTF-validated autonomous pen tester available. Covers what Shannon and Strix cannot: the
external network perspective against the VPS. Runs against a staging clone, never production.

- **CTF record:** 99.04% mean percentile across 5 major 2025 competitions, $50K win at
  Neurogrid, HackTheBox top 500 worldwide in one week, Rank 1 in Dragos OT CTF during peak
  hours. 3,600× faster than humans on scanning; 11× overall in human+AI workflows.
- **Coverage:** Port scanning, CVE exploitation chains, misconfiguration, credential stuffing
- **License:** Open source (aliasrobotics/cai)
- **Trigger:** Monthly (1st of month, 4am) against staging VPS only

### Integration Architecture

```
Shannon (whitebox, weekly)   ─┐
Strix   (blackbox, nightly)  ─┼──► security_scan_results table
CAI     (blackbox, monthly)  ─┘         │
                                         ├─ severity >= HIGH  → human_review_queue (type='pentest')
                                         ├─ severity >= CRITICAL → GitHub issue (github MCP)
                                         └─ all results → System Health dashboard
```

### New file: `src/cron/security-scan.js`

Orchestrates all three tools, normalises output into a common schema:

```javascript
// Normalised finding schema
{
  tool: 'shannon' | 'strix' | 'cai',
  scan_date: ISO8601,
  severity: 'critical' | 'high' | 'medium' | 'low' | 'info',
  vuln_type: string,          // 'sqli', 'ssrf', 'auth_bypass', etc.
  title: string,
  proof_of_concept: string,   // all three tools provide PoC
  file_path: string | null,   // Shannon only — maps to source file
  line_number: number | null, // Shannon only
  remediation: string,
}
```

### New DB migrations

**`db/migrations/065-security-scan-results.sql`**:

```sql
CREATE TABLE IF NOT EXISTS security_scan_results (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tool TEXT NOT NULL CHECK(tool IN ('shannon','strix','cai')),
  scan_date TEXT NOT NULL DEFAULT (datetime('now')),
  target TEXT NOT NULL,
  severity TEXT NOT NULL CHECK(severity IN ('critical','high','medium','low','info')),
  vuln_type TEXT NOT NULL,
  title TEXT NOT NULL,
  description TEXT,
  proof_of_concept TEXT,
  file_path TEXT,
  line_number INTEGER,
  remediation TEXT,
  raw_output TEXT,
  human_review_id INTEGER REFERENCES human_review_queue(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_scan_results_severity ON security_scan_results(severity);
CREATE INDEX idx_scan_results_tool_date ON security_scan_results(tool, scan_date DESC);
```

**`db/migrations/066-seed-security-scan-crons.sql`**: Seeds `shannonScan` (weekly),
`strixScan` (nightly), `caiScan` (monthly) in `cron_jobs` — all initially disabled.

### Dashboard Integration

**System Health page** — add "🔐 Penetration Test Results" section: open Critical/High tiles,
findings table filterable by tool/severity/date, 90-day trend chart.

**Human Review page** — surface `type='pentest'` items with severity badge, PoC in expander,
"False Positive" / "Fixed" / "Accepted Risk" buttons. Shannon findings link to source file:line.

### New environment variables

```bash
PENTEST_TARGET_URL=http://test-app:3000   # Shannon + Strix target
CAI_STAGING_TARGET=staging.333method.net  # CAI external target (staging VPS only)
SHANNON_REPO_PATH=/app                    # source code path in pentest container
PENTEST_GITHUB_ISSUE_THRESHOLD=critical   # create GitHub issue for critical findings only
```

---

## Part 16: Windows Desktop Worker Nodes (Headless Docker)

**Added:** 2026-02-21

Idle Windows desktop PCs can run pipeline worker tasks as headless Docker containers, connected
to the VPS via NetBird VPN. This section covers the security architecture needed to prevent
Windows telemetry from observing container internals or traffic payloads.

### Docker Backend: Hyper-V (Not WSL2)

The backend choice is the primary security decision:

| Backend                           | Isolation        | Windows kernel visibility into container                                          |
| --------------------------------- | ---------------- | --------------------------------------------------------------------------------- |
| **WSL2** (Docker Desktop default) | Shared kernel    | High — telemetry at kernel level sees WSL2 processes, file I/O, network metadata  |
| **Hyper-V**                       | Full VM boundary | Low — Windows sees an opaque VM; cannot observe processes or file contents inside |

**Always use Hyper-V backend.** Configure in Docker Desktop settings or via:

```powershell
# Switch to Hyper-V backend
"C:\Program Files\Docker\Docker\DockerCli.exe" -SwitchWindowsEngine
```

### What Windows Can and Cannot See (Hyper-V)

**Windows CAN observe:**

- That a Hyper-V VM is running and how much RAM/CPU it uses
- Network connections: source/dest IP, port, byte count (even for encrypted traffic)
- File I/O on any Windows-path volumes mounted into the container (`C:\`, `D:\` paths)
- Process name: `com.docker.backend.exe`, `dockerd` — not what's inside

**Windows CANNOT observe (with Hyper-V):**

- Processes running inside the container
- File contents inside the container's virtual filesystem
- Payload content of WireGuard/NetBird encrypted traffic
- Environment variables, secrets, API keys inside the container

### Windows Telemetry Threats and Mitigations

**Windows Recall** — takes screenshots every few seconds and indexes with AI. Headless Docker
has no UI to capture, but any management terminal/VSCode on the host would be indexed.

```powershell
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsAI" /v DisableAIDataAnalysis /t REG_DWORD /d 1 /f
```

**DiagTrack (Connected User Experiences and Telemetry)** — sends process activity, network
usage, and installed software to Microsoft. Cannot be fully zeroed on Windows Home/Pro.

```powershell
sc stop DiagTrack && sc config DiagTrack start= disabled
sc stop dmwappushservice && sc config dmwappushservice start= disabled
```

**Windows Defender volume scanning** — scans every file written to Windows-mounted paths.
**Mitigation:** use named Docker volumes only — never mount `C:\` paths into containers. All
data stays inside the Docker VM's virtual disk, invisible to Defender.

**Copilot / AI features** — has clipboard access and screen observation.

```powershell
reg add "HKCU\SOFTWARE\Policies\Microsoft\Windows\WindowsCopilot" /v TurnOffWindowsCopilot /t REG_DWORD /d 1 /f
```

**Block telemetry at network level** — use the `WindowsSpyBlocker` hosts list
(github.com/crazy-max/WindowsSpyBlocker) via router/firewall or local hosts file to block
Microsoft telemetry endpoints entirely, regardless of which services are running.

### Windows LTSC — Licensing Reality

LTSC (Long Term Servicing Channel) is the cleanest option: no Cortana, no Copilot, telemetry
can be set to `0` via Group Policy (impossible on Home/Pro). However it requires volume
licensing or an OEM embedded license.

**Realistic options:**

- **Windows 10/11 Pro** — most GP telemetry settings work, just not `AllowTelemetry=0`.
  Combine with DiagTrack disabled + WindowsSpyBlocker for acceptable posture.
- **90-day evaluation ISO** — Microsoft's Evaluation Center provides a full LTSC evaluation
  ISO, freely downloadable. After 90 days it watermarks the desktop — a headless Docker host
  never shows the desktop, so the watermark is never visible. Not licensed for production use.
- **Linux instead** — eliminates the entire problem. Ubuntu Server, Alpine, or NixOS have zero
  telemetry by design. If the PCs can boot Linux (even dual-boot), strongly prefer this.

If these are machines you fully control and can wipe, **install Ubuntu Server or Alpine Linux**
and run Docker natively. Same container image, zero Windows telemetry surface, no licensing
complexity.

### Container Architecture for Worker Nodes

```
Windows Host (Hyper-V, telemetry disabled)
  └─ Docker (Hyper-V backend)
       └─ Container: pipeline-worker
            ├─ ENTRYPOINT: netbird up --setup-key $NETBIRD_SETUP_KEY
            │   └─ joins NetBird mesh → gets 100.x.x.x private IP
            ├─ All app traffic routes through WireGuard tunnel to VPS
            ├─ Worker pulls tasks from agent_tasks table via VPN
            └─ Named volumes only (no Windows path mounts)
```

Windows sees: a Hyper-V VM, encrypted traffic to the VPS NetBird IP. Nothing else.

### Worker Node Security Constraints

- **No secrets mounted from Windows filesystem** — secrets injected via NetBird-secured
  environment at startup, fetched from VPS via encrypted tunnel
- **No outbound connections except VPN endpoint** — firewall rule: block all outbound from
  Docker VM IP except UDP 51820 (WireGuard) to VPS
- **Read-only image** — worker container image is immutable; no writes except named volumes
- **No Docker socket mount** — worker containers never mount `/var/run/docker.sock`
- **Rotate setup keys** — NetBird setup keys are single-use; generate one per machine

### Minimum Hardening Script (Windows)

```powershell
# Run as Administrator before deploying worker container

# Disable Recall
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsAI" /v DisableAIDataAnalysis /t REG_DWORD /d 1 /f

# Disable Copilot
reg add "HKCU\SOFTWARE\Policies\Microsoft\Windows\WindowsCopilot" /v TurnOffWindowsCopilot /t REG_DWORD /d 1 /f

# Disable DiagTrack (telemetry service)
sc stop DiagTrack && sc config DiagTrack start= disabled
sc stop dmwappushservice && sc config dmwappushservice start= disabled

# Set telemetry to lowest level possible on Pro (0 requires Enterprise/LTSC)
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\DataCollection" /v AllowTelemetry /t REG_DWORD /d 1 /f

# Disable Windows Search indexing (reduces file observation)
sc stop WSearch && sc config WSearch start= disabled
```

### Backup Cron Refactoring Note

⚠️ **The current `backupDatabase` handler in `src/cron.js` (line 368) must be refactored**
before the Docker/multi-node architecture goes live.

**Current behaviour:** Creates local `db/sites-backup-TIMESTAMP.db` files via SQLite's online
backup API. Vacuums before backup. Stores everything in `db/` on the same machine.

**Problems with the new architecture:**

1. Worker nodes do not have (and should not have) access to `db/sites.db` — only the VPS does
2. Local-only backups are lost if the VPS disk fails — no offsite copy
3. The `backup` Docker container (restic + Backblaze B2, Part 7) does offsite backup, but
   the existing cron handler doesn't know about it

**Required refactoring:**

- Keep local SQLite backup as a snapshot (good for fast recovery)
- Add restic push step: after local backup completes, trigger the `backup` container to run
  `restic backup` and push to B2
- OR: remove `backupDatabase` from `cron.js` entirely and let the `backup` container run
  on its own NixOS systemd timer (decoupled from app-level cron)
- Ensure backup only runs on the VPS — worker nodes must not attempt DB backup
- Add verification: `restic check` and `restic snapshots` results logged to DB/dashboard

---

## Part 17: Bootable USB Worker Node (NixOS Live Image)

**Added:** 2026-02-22

A bootable NixOS USB stick that non-technical users insert, boot into, and lend their machine
as a pipeline worker — with full remote management by the operator and zero traces left on the
host machine when unplugged.

### Why Bootable USB is Better than Windows Docker for Non-Techs

| Factor                      | NixOS Bootable USB                                | Windows Docker (Part 16)                       |
| --------------------------- | ------------------------------------------------- | ---------------------------------------------- |
| Host OS telemetry           | ✅ Zero — Windows never runs                      | ⚠️ Hyper-V boundary but Windows kernel present |
| Opt-out                     | ✅ Unplug USB, reboot — done                      | ⚠️ Stop Docker, revert settings                |
| Data on host machine        | ✅ None — amnesic by default                      | ⚠️ Docker named volumes persist                |
| Non-tech setup              | ✅ Reboot into USB                                | ⚠️ Operator must configure Windows first       |
| Hardware compat             | ⚠️ Good; exotic Wi-Fi / Nvidia may need attention | ✅ Native Windows drivers                      |
| Integration with plan       | ✅ Same `333Method-infra/` flake as VPS           | ⚠️ Separate PowerShell / Docker config         |
| User apps (browser, office) | ✅ Declared in flake, nothing else installable    | ✅ Familiar Windows apps                       |

**Use USB for:** machines owned by lenders who want zero commitment and zero risk.
**Keep Windows Docker (Part 16) for:** machines where the owner wants to keep using Windows
alongside the worker simultaneously.

### Boot Architecture

```
USB stick (USB 3.0+, 32GB+)
  └─ NixOS squashfs + systemd-boot
       │  kernelParams = ["copytoram" "quiet" "splash"]
       ↓
RAM (~3-5 min copy on USB 3.0, then USB optional)
  ├─ NetworkManager (Wi-Fi / Ethernet)
  ├─ KDE Plasma 6 desktop (SDDM, Wayland, auto-login as 'worker')
  │   ├─ LibreWolf (-bin)     ← pre-compiled; avoids multi-hour source build
  │   └─ LibreOffice (-still) ← stable branch; better binary cache hit rate
  ├─ NetBird (WireGuard mesh VPN → joins VPS mesh at boot)
  ├─ VPN kill switch (iptables OUTPUT: drops all non-wt0 traffic)
  ├─ Docker → pipeline-worker container
  │             (starts after netbird-setup.service confirms VPN connected)
  └─ RustDesk (connects to self-hosted relay on VPS over NetBird)
```

**USB hardware:** USB 3.0 minimum, USB 3.1/3.2 recommended. Any 32 GB USB 3.x stick (~$10–15,
e.g. Samsung FIT, SanDisk Ultra) is sufficient. USB 3.2 Gen 2 not required — the bottleneck
after USB read is RAM decompression, not USB bandwidth.

After `copytoram` completes, the USB can remain plugged in or be removed — everything runs
from RAM. Disk I/O from the containers goes to the VPS over the encrypted NetBird tunnel.

### NixOS Flake Config: `hosts/worker-usb/configuration.nix`

Key decisions reflected in the actual config (see `333Method-infra/hosts/worker-usb/configuration.nix`):

```nix
{ config, pkgs, lib, ... }: {

  # copytoram + quiet boot (lender sees splash, not kernel log)
  boot.kernelParams = lib.mkAfter [ "copytoram" "quiet" "splash" "loglevel=3" ];

  # KDE Plasma 6 (not GNOME) — better hardware compat, more familiar UX
  services.desktopManager.plasma6.enable = true;
  services.displayManager = {
    sddm = { enable = true; wayland.enable = true; };
    autoLogin = { enable = true; user = "worker"; };
  };

  # Locked-down worker user: no wheel (no sudo), no terminal in menu
  users.users.worker = {
    isNormalUser = true;
    extraGroups = [ "networkmanager" "audio" "video" "docker" ];
    # No "wheel" → no sudo
  };
  nix.settings.allowed-users = [ "root" ];

  # -bin packages: upstream pre-compiled binaries → fast ISO builds regardless of cache
  environment.systemPackages = with pkgs; [
    librewolf-bin           # avoids multi-hour source build; extensions TBA
    libreoffice-qt6-still   # stable branch, better cache hit rate than -fresh
    rustdesk                # stable binary; rustdesk-flutter less well cached
  ];

  # Screen off after 5 min — NEVER sleep/suspend
  services.logind = {
    lidSwitch = "ignore"; lidSwitchDocked = "ignore"; lidSwitchExternalPower = "ignore";
    extraConfig = "HandleSuspendKey=ignore\nHandleHibernateKey=ignore\nIdleAction=ignore";
  };
  systemd.targets.sleep.enable = false;
  systemd.targets.suspend.enable = false;
  systemd.targets.hibernate.enable = false;
  systemd.targets.hybrid-sleep.enable = false;
  # KDE power profile: screen off at 5 min, no sleep (written to /etc/xdg/kdedefaults/)

  # Laptop battery: graceful shutdown at 3% critical, alert at 5%
  services.upower = {
    enable = true;
    criticalPowerAction = "PowerOff";  # safe shutdown, not suspend
    percentageCritical  = 5;
    percentageAction    = 3;
  };

  # VPN kill switch: all traffic through NetBird (wt0), everything else dropped
  networking.firewall.extraCommands = ''
    iptables -A OUTPUT -o lo  -j ACCEPT
    iptables -A OUTPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
    iptables -A OUTPUT -p udp --dport 53  -j ACCEPT   # DNS pre-VPN
    iptables -A OUTPUT -p udp --dport 51820 -j ACCEPT  # WireGuard handshake
    iptables -A OUTPUT -p udp -m multiport --dports 443,3478,5349 -j ACCEPT
    iptables -A OUTPUT -o wt0 -j ACCEPT                # all NetBird traffic OK
    iptables -A OUTPUT -j REJECT --reject-with icmp-net-prohibited
  '';

  services.netbird.enable = true;
  virtualisation.docker.enable = true;

  services.openssh = {
    enable = true;
    settings = { PasswordAuthentication = false; PermitRootLogin = "prohibit-password"; };
  };
  users.users.root.opensshAuthorizedKeys.keys = [ "ssh-ed25519 AAAA... operator@333method" ];
}
```

### RustDesk: Self-Hosted Relay on VPS

RustDesk has a NixOS service module. Add to the VPS `configuration.nix`:

```nix
# In 333Method-infra/hosts/production/configuration.nix
services.rustdesk-server = {
  enable = true;
  openFirewall = true;  # UDP 21116, TCP 21115/21117
  relayIP = "100.x.x.x";  # VPS NetBird IP (not public IP — relay only reachable via mesh)
};
```

Worker USB image comes pre-configured to point to your relay:

```nix
# In hosts/worker-usb/configuration.nix
environment.etc."rustdesk/config.toml".text = ''
  relay-server = "100.x.x.x"   # VPS NetBird IP
  key = "self-hosted-relay-pubkey"
'';
```

From your machine (with NetBird installed), open RustDesk → connect to the worker's ID. Full
visual desktop. The relay is on your VPS and only reachable via the NetBird mesh — not exposed
to the public internet.

### NetBird Setup Key Security

Baking a NetBird setup key into the USB image is a risk if the stick is lost. Two safer approaches:

**Option A (simpler):** Single-use setup keys with 24h expiry — generated fresh per USB stick,
expire fast. A stolen old USB can't join the mesh.

**Option B (more secure):** No key in image. On first boot, the worker shows a 6-digit
pairing code on screen; the operator approves it in the NetBird management console. Worker
only joins the mesh after manual approval.

### Locked-Down User Environment

What `worker` can do:

- Browse the web (LibreWolf — privacy-hardened, extensions TBA)
- Use LibreOffice (Still branch — stable)
- Connect to Wi-Fi via KDE NetworkManager applet
- Disconnect Wi-Fi
- Shut down / reboot the machine

What `worker` cannot do:

- Install any software (`nix-env` unavailable, no sudo, no apt/dnf)
- Access terminal (Konsole masked from KDE application menu via `applications-merged/hide-terminal.menu`)
- See or modify Docker containers (no docker CLI in worker PATH)
- Browse non-VPN internet (VPN kill switch drops all non-wt0 traffic)
- Modify NetBird config (root only)
- Access VPS internal network (NetBird ACLs restrict worker to pipeline ports only)

What the **operator** can do remotely:

- Full visual desktop via RustDesk
- Full root SSH access via NetBird (only when VPN is connected)
- `nixos-rebuild switch` to push config changes (since it's RAM-based, changes persist until next reboot — next reboot resets to image state unless USB is rebuilt)
- Install packages temporarily via `nix-env -i` as root (reverts on reboot)
- Kill / restart pipeline-worker container

### USB Size and Boot Time Expectations

| USB spec            | Copy-to-RAM time | Notes                         |
| ------------------- | ---------------- | ----------------------------- |
| USB 2.0             | 10-20 min        | Painful — strongly discourage |
| USB 3.0             | 2-5 min          | Acceptable                    |
| USB 3.1/3.2         | 1-2 min          | Recommended                   |
| USB 3.2 + fast NAND | <1 min           | Best experience               |

NixOS image size estimates:

- Base NixOS + GNOME + Firefox + LibreOffice: ~3.5 GB
- Plus Docker images baked in (pipeline-worker): +2-4 GB
- **Total: ~6-8 GB** → 32GB USB 3.0 is the minimum practical recommendation

### Building the Image

From the `333Method-infra/` flake:

```bash
# Build the ISO
nix build .#nixosConfigurations.worker-usb.config.system.build.isoImage

# Flash to USB (replace /dev/sdX with your USB device)
sudo dd if=result/iso/*.iso of=/dev/sdX bs=4M status=progress conv=fsync
```

### Amnesic vs Persistent Mode

**Default (amnesic):** Every boot is identical to the image. No data survives reboot.
Good for: security, known-good state, easy rollback.

**Optional persistent partition:** A second partition on the USB can store Docker image cache
(to avoid re-pulling worker image on every boot) and NetBird peer state (avoids re-pairing).
No user data is ever written there — only operator-managed system state.

```nix
# Enable persistence for Docker cache and NetBird state only
fileSystems."/var/lib/docker" = {
  device = "/dev/disk/by-label/PERSIST";
  fsType = "ext4";
  options = [ "nofail" ];  # boot proceeds even if partition missing
};
```

### Non-Technical User Instructions (fits on a card)

```
1. Plug in the USB stick
2. Restart your computer
3. If Windows starts instead: restart again, press [F12] / [Delete] during boot logo
   → Select "USB Drive" from the menu
4. Wait for the desktop to appear (~3 minutes)
5. Connect to Wi-Fi if needed (click the network icon, top-right)
6. Leave the computer on — it's helping run our servers
7. To stop: unplug the USB stick and restart normally
   (Your Windows is completely untouched)
```

---

## Parts 6–17: Files to Create/Modify

| Action | Path                                                                                      |
| ------ | ----------------------------------------------------------------------------------------- |
| CREATE | `src/cron/audit-log-review.js`                                                            |
| CREATE | `src/agents/openclaw-bootstrap.js`                                                        |
| CREATE | `src/agents/contexts/openclaw-bootstrap.md`                                               |
| CREATE | `scripts/load-secrets-to-env.js`                                                          |
| CREATE | `scripts/mcp-gateway.js` — SSE MCP gateway (postgres + fetch) for OpenClaw                |
| CREATE | `db/migrations/062-create-audit-reviews.sql`                                              |
| CREATE | `db/migrations/063-seed-audit-review-cron.sql`                                            |
| CREATE | `db/migrations/064-openclaw-readonly-views.sql`                                           |
| MODIFY | `src/cron.js` — register `auditLogReview` handler                                         |
| MODIFY | `dashboard/utils/database.py` — add 2 query functions                                     |
| MODIFY | `dashboard/pages/6_🖥️_System_Health.py` — add audit review section                        |
| MODIFY | `dashboard/pages/8_🤝_Human_Review.py` — add audit alerts section                         |
| MODIFY | `.env.example` — add Papertrail, NetBird, B2, GitHub audit, pentest vars                  |
| CREATE | `src/cron/security-scan.js` — Shannon / Strix / CAI orchestrator + normaliser             |
| CREATE | `db/migrations/065-security-scan-results.sql`                                             |
| CREATE | `db/migrations/066-seed-security-scan-crons.sql`                                          |
| MODIFY | `src/cron.js` — register `shannonScan`, `strixScan`, `caiScan`, refactor `backupDatabase` |
| MODIFY | `dashboard/pages/6_🖥️_System_Health.py` — add pentest results section                     |
| MODIFY | `dashboard/pages/8_🤝_Human_Review.py` — surface `type='pentest'` items                   |
| CREATE | `docker/pipeline-worker/Dockerfile` — headless worker image for Windows PCs               |
| CREATE | `scripts/windows-worker-harden.ps1` — PowerShell telemetry hardening script               |

**External (not in app repo — in `333Method-infra/` repo):**

Initial commit `a563541`, package fixes `9f32cde` — all files created 2026-02-22.

| Status     | Path                                                                                             |
| ---------- | ------------------------------------------------------------------------------------------------ |
| ✅ CREATED | `flake.nix` + `.gitignore` + `.sops.yaml`                                                        |
| ✅ CREATED | `hosts/production/configuration.nix` — Hetzner CX41 config                                       |
| ✅ CREATED | `hosts/production/disko.nix` — disk layout for nixos-anywhere                                    |
| ✅ CREATED | `hosts/worker-usb/configuration.nix` — KDE, copytoram, VPN kill switch, power mgmt               |
| ✅ CREATED | `modules/containers.nix` — all Docker services incl. mcp-gateway, openclaw                       |
| ✅ CREATED | `modules/monitoring.nix` — auditd, rsyslog→Papertrail, git-watcher, NetBird, RustDesk relay      |
| ✅ CREATED | `modules/security.nix` — SSH hardening, fail2ban, AppArmor, OpenClaw UID 9000                    |
| ✅ CREATED | `modules/backup.nix` — restic → B2, daily systemd timer                                          |
| ✅ CREATED | `modules/secrets.nix` — sops-nix declarations for all secrets                                    |
| ✅ CREATED | `modules/mcp.nix` — OpenClaw workspace dir + MCP intent documentation                            |
| ✅ CREATED | `modules/worker.nix` — KDE autostart, RustDesk service, status writer                            |
| ✅ CREATED | `secrets/production.yaml` — plaintext template (encrypt with sops before committing real values) |
| ✅ CREATED | `scripts/build-usb.sh` — `nix build` wrapper with progress output                                |
| ✅ CREATED | `scripts/flash-usb.sh` — `dd` wrapper with safety checks                                         |
| ✅ CREATED | `scripts/worker-config-server.js` — Bearer-token HTTP config endpoint on NetBird                 |
| PENDING    | Encrypt `secrets/production.yaml` with sops before pushing to remote                             |
| PENDING    | Add VPS SSH host age key to `.sops.yaml` after first nixos-anywhere deploy                       |

---

## Parts 6–17: Implementation Sequence

**Week 1 — Infrastructure Foundation (human operator only, not OpenClaw)**

1. Provision Hetzner CX41 VPS with SSH key auth only
2. Install NixOS via nixos-anywhere from local machine (or use Hetzner's marketplace image)
3. Deploy auditd + rsyslog → Papertrail (audit foundation before anything else)
4. Deploy git-watcher service → private GitHub audit repo
5. Set iptables rules (block OpenClaw UID 9000 from audit sinks)
6. Create all secrets via SSH session (Docker Secrets or sops-nix)
7. Deploy base services (PostgreSQL, Redis) — verify healthy

**Week 2 — NetBird VPN + OpenClaw Bootstrap** 8. Install NetBird, verify Rosenpass active (`netbird status --detail`) 9. Apply WireGuard subnet iptables rule for dashboard (port 8501) 10. Deploy OpenClaw container + docker-socket-proxy (read-only configuration) 11. Create bootstrap agent tasks in `agent_tasks` table (human operator) 12. OpenClaw processes tasks → writes config templates to workspace 13. Human reviews templates in git-watcher GitHub repo 14. Secrets-init fills `{{PLACEHOLDER}}` values → services start

**Week 3 — AI Audit Review Pipeline** 15. Run DB migrations 062 + 063 + 064 16. Implement `audit-log-review.js` 17. Register handler in `cron.js` 18. Add query functions to `dashboard/utils/database.py` 19. Add UI sections to System Health + Human Review pages 20. Update `.env.example` 21. Enable cron job: `npm run cron:enable auditLogReview` 22. Run first review manually: `npm run cron:run auditLogReview`

**Week 4 — Validation** 23. Simulate a bad command in OpenClaw workspace; verify Papertrail captures it 24. Run audit review; verify risk level escalates; verify Human Review alert appears 25. Verify OpenClaw cannot reach PostgreSQL from its network 26. Verify OpenClaw cannot read `/run/secrets` or Docker Secrets via API 27. Test backup restore from Backblaze B2

**Week 5 — Penetration Testing Pipeline** 28. Run DB migrations 065 + 066 29. Deploy `pentest` container (Shannon + Strix pre-installed) 30. Run first Shannon + Strix scans manually; verify findings in `security_scan_results` 31. Provision staging VPS clone on Hetzner; run first CAI external scan 32. Enable cron jobs: `shannonScan` (weekly), `strixScan` (nightly), `caiScan` (monthly) 33. Add pentest sections to System Health + Human Review dashboard pages 34. Triage first real findings — mark false positives, fix genuine issues

**Week 6 — USB Worker Nodes** _(333Method-infra/ repo already created — 2026-02-22)_

35. ✅ `333Method-infra/` flake created with all modules (commits a563541, 9f32cde)
36. ✅ RustDesk relay included in `modules/monitoring.nix` (not a separate module)
37. Build USB ISO on NixOS host: `./scripts/build-usb.sh` (wrapper for `nix build`)
38. Flash to test USB (USB 3.0+, 32GB): `sudo ./scripts/flash-usb.sh result/iso/*.iso /dev/sdX`
39. Boot on a test machine; verify:
    - KDE Plasma 6 auto-login as `worker`
    - NetBird connects and gets 100.x.x.x IP
    - VPN kill switch active (non-VPN traffic dropped)
    - `pipeline-worker` container starts after VPN connects
    - RustDesk client connects to VPS relay over NetBird
    - SSH accessible from operator machine via NetBird IP
    - Screen turns off after 5 min; machine never suspends
    - On laptop: disconnect power; verify graceful shutdown at 3% battery
40. Verify `worker` user cannot install packages, open terminal, or reach VPS internal network
41. Encrypt `secrets/production.yaml` with sops; push `333Method-infra/` to private remote
42. Test opt-out: unplug USB; verify host machine boots back to original OS cleanly
43. Flash and distribute to first batch of lenders

---

## Parts 6–17: Verification Checklist

```bash
# Audit sidecar
ausearch -k openclaw_exec --start recent           # kernel-level exec capture working
docker exec openclaw curl -s https://papertrailapp.com  # must fail (iptables block)
docker exec openclaw curl http://docker-socket-proxy:2375/v1.41/secrets  # must 403

# Secrets isolation
docker inspect openclaw | grep -i secret           # must show no secrets mounted
docker exec openclaw env | grep -i KEY             # must show nothing

# AI audit review
npm run cron:run auditLogReview
sqlite3 db/sites.db "SELECT id, risk_level, summary FROM audit_reviews ORDER BY id DESC LIMIT 1"
sqlite3 db/sites.db "SELECT * FROM human_review_queue WHERE type='security' ORDER BY id DESC LIMIT 1"

# Dashboard
# Open http://<wireguard-ip>:8501 → System Health → AI Audit Log Reviews section
# Open http://<wireguard-ip>:8501 → Human Review → AI Audit Review Alerts section

# Server cloning
nix run nixpkgs#nixos-anywhere -- --flake .#production root@new-server-ip  # provisions clean clone

# MCP isolation checks
docker exec openclaw npx -y @modelcontextprotocol/server-filesystem /run/secrets  # must fail (path not listed)
docker exec openclaw curl http://mcp-gateway:3000/sse  # must succeed (openclaw-net reachable)
docker exec pipeline curl http://mcp-gateway:3000/sse  # must fail (pipeline on internal, not openclaw-net)

# Fetch allowlist enforcement (from openclaw container)
docker exec openclaw node -e "
  // Simulate fetch MCP call to non-allowlisted domain
  fetch('http://mcp-gateway:3000/fetch?url=https://papertrailapp.com')
    .then(r => r.json())
    .then(d => console.log(d.error))   // must show 'domain not in allowlist'
"

# Postgres view restriction (from openclaw via gateway)
# Query must succeed for sanitized view, fail for raw table
docker exec openclaw node -e "
  // Via gateway postgres MCP
  // SELECT * FROM openclaw_v_agent_tasks WHERE assigned_to='openclaw'  → should succeed
  // SELECT * FROM sites LIMIT 1                                        → should fail (no grant)
"

# GitHub MCP audit issue creation (from pipeline container)
npm run cron:run auditLogReview  # if risk_level >= 4 in test data, GitHub issue must appear

# Penetration testing (Part 15)
npm run pentest shannon            # Shannon whitebox scan → findings in security_scan_results
npm run pentest strix              # Strix dynamic scan → PoC exploits captured
npm run pentest cai                # CAI external scan → requires CAI_STAGING_TARGET set
sqlite3 db/sites.db "SELECT tool, severity, vuln_type, title FROM security_scan_results ORDER BY created_at DESC LIMIT 10"
sqlite3 db/sites.db "SELECT * FROM human_review_queue WHERE type='pentest' ORDER BY id DESC LIMIT 5"

# Backup cron refactoring (Part 16 — verify before go-live)
sqlite3 db/sites.db "SELECT name, last_run, status FROM cron_jobs WHERE name='backupDatabase'"
# After refactor: verify restic snapshot exists in B2
restic -r b2:method333-backups-prod snapshots    # must show at least one snapshot
restic -r b2:method333-backups-prod check        # must return 'no errors were found'

# Windows worker node (Part 16)
# From Windows host with worker container running:
netbird status                         # must show connected to mesh, assigned 100.x.x.x IP
docker exec pipeline-worker env | grep KEY   # must show nothing (no secrets in env)
docker exec pipeline-worker curl https://papertrailapp.com  # must fail (VPN-only egress)
# Verify Docker backend is Hyper-V (not WSL2):
docker info | grep -i "Operating System"   # must show "Docker Desktop" with Hyper-V

# USB worker node (Part 17) — run from booted USB machine
cat /proc/meminfo | grep MemAvailable    # should show most of RAM free (OS is in RAM)
findmnt /                                # root should be tmpfs (copytoram succeeded)
netbird status                           # must show connected, 100.x.x.x IP assigned
docker ps                                # pipeline-worker container must be running
id worker                                # must NOT include 'wheel' or 'sudo' groups
which nix-env                            # must return nothing (not in worker PATH)
which terminal                           # must return nothing (no terminal for user)

# From operator machine (via NetBird):
ssh root@<worker-100.x.x.x>             # must succeed with operator SSH key
rustdesk                                  # connect to worker RustDesk ID → desktop visible

# Opt-out test:
# 1. Pull USB while worker container is running
# 2. Machine reboots to original OS
# 3. Verify on VPS: task that was in-progress is re-queued or marked failed
sqlite3 db/sites.db "SELECT id, status FROM agent_tasks WHERE assigned_to LIKE '%worker%' ORDER BY updated_at DESC LIMIT 5"
```

---

## Part K: USB Worker Cluster Architecture (Added 2026-02-23)

### Context

USB worker nodes run the full pipeline + agent system (Dev/QA/Security/Architect), not just browser
automation. Multiple spare desktops process work in parallel — requiring shared state. PostgreSQL
must live on the always-on VPS; USB desktops connect to it over NetBird.

### Revised Machine Roles

| Machine                         | Services                                                                  | Notes                                         |
| ------------------------------- | ------------------------------------------------------------------------- | --------------------------------------------- |
| **VPS (Hostinger KVM 1, 4 GB)** | PostgreSQL + Redis + OpenClaw + cron + inbound webhooks + **scan-poller** | Always-on data hub                            |
| **USB Desktop Workers (1–N)**   | Pipeline stages + agents — connect to VPS over NetBird                    | Spin up spare desktops when processing needed |

**Why VPS holds the database:** USB desktops can be unplugged. PostgreSQL on a desktop = data
unavailable when that desktop sleeps or reboots. VPS is always-on.

**Why USB desktops run compute:** Playwright browser sessions, agent LLM calls, scoring — all
CPU/RAM-heavy. No Playwright on the VPS.

> **TODO — scan-poller migration (2026-03-10):** `src/api/free-score-api.js` currently runs as a
> systemd service on the NixOS home machine, polling the Cloudflare Worker every 5 minutes for new
> free scan results and archiving them to SQLite. This is fine for now (5-min polling = ~288 KV
> reads/day, effectively free; no user-facing latency since results are already returned to the
> browser synchronously). However it means archiving stops when the home machine is off.
>
> **Target state:** Move scan-poller to the VPS (Hostinger KVM 1) as a lightweight systemd service.
> The VPS has no Playwright and only ~80 MB overhead for a Node.js HTTP poller — well within budget.
> Alternatively, deploy to a USB-booted PC on the home network that stays on 24/7.
>
> **pub-sub is not needed:** The Worker already returns the scan result synchronously to the user's
> browser. The daemon archiving to SQLite is a background CRM/analytics step with no user-facing
> latency requirement. ntfy.sh or Cloudflare Queues would add complexity without benefit until the
> drip email sequence needs sub-minute trigger latency.

### Memory Budget: VPS (KVM 1, 4 GB)

| Service                                                | Est. RAM     |
| ------------------------------------------------------ | ------------ |
| PostgreSQL (shared_buffers=256 MB, max 10 connections) | ~600 MB      |
| Redis                                                  | ~100 MB      |
| OpenClaw (node + claude-code)                          | ~400 MB peak |
| docker-socket-proxy                                    | ~20 MB       |
| worker-config-server                                   | ~50 MB       |
| Inbound webhook server (Twilio/Resend)                 | ~80 MB       |
| OS overhead                                            | ~300 MB      |
| **Total baseline**                                     | ~1.55 GB     |
| **Peak (OpenClaw active)**                             | ~2.0 GB      |

Fits comfortably in 4 GB with headroom for spikes.

### Concurrent Job Claiming: `FOR UPDATE SKIP LOCKED`

PostgreSQL's native concurrent batch claiming — no Redis job queue needed for pipeline claiming:

```sql
-- Each USB worker runs this in its own transaction
BEGIN;
SELECT id, domain, landing_page_url FROM sites
  WHERE status = 'found'
  ORDER BY created_at
  LIMIT 10
  FOR UPDATE SKIP LOCKED;   -- skips rows locked by other workers, zero collision
-- process rows ...
UPDATE sites SET status = 'assets_captured' WHERE id = ANY($1);
COMMIT;
```

Multiple workers each grab their own batch simultaneously. Redis remains useful for: rate limit
state, distributed locks for non-DB resources, pub/sub for real-time dashboard updates.

### `containers.nix` Split: VPS Profile vs Worker Profile

The current `modules/containers.nix` mixes VPS and USB concerns. Split into two files in
`333Method-infra/`:

**`modules/containers-vps.nix`** — imported by `hosts/production/configuration.nix`:

- `postgresql` (active — data hub)
- `redis` (active)
- `openclaw` (active)
- `docker-socket-proxy` (active)
- `worker-config-server` (active)
- `inbound-webhook` (Express server for Twilio/Resend webhooks)
- `dashboard` (started manually when needed, or always-on)

**`modules/containers-worker.nix`** — imported by `hosts/worker-usb/configuration.nix`:

- `pipeline` container — `DATABASE_URL=postgresql://...@<vps-netbird-ip>:5432/333method`
- `agent-worker` container — same `DATABASE_URL`, runs Dev/QA/Security/Architect agents
- `REDIS_URL=redis://:<password>@<vps-netbird-ip>:6379`
- **No local PostgreSQL/Redis** — connects to VPS over NetBird

### SQLite → PostgreSQL Migration: Now a Hard Prerequisite

Multi-worker USB desktops require shared state. The app currently uses `better-sqlite3`. This was
already Phase 1 of the original plan; with multiple desktop workers it is no longer optional.

**Key changes:**

- `better-sqlite3` → `pg` (node-postgres) with connection pool
- Schema: `AUTOINCREMENT` → `SERIAL`, `datetime('now')` → `NOW()`, remove `PRAGMA` statements
- Pipeline stage queries: add `FOR UPDATE SKIP LOCKED` to all stage-claiming `SELECT`s
- Config: `DATABASE_PATH` env var → `DATABASE_URL=postgresql://user:pass@vps-ip:5432/333method`

**Cost update:** Remove Neon $7–8/mo from Part 3 totals. PostgreSQL is self-hosted on Hostinger
VPS (already paid). Ongoing infrastructure cost = Hostinger ($131.88/year, ~$11/mo) only.

### Hostinger VPS Deployment (Updates Part 12)

Part 12 recommended Hetzner. Hostinger is pre-paid — moot for now.

1. **nixos-infect** (not nixos-anywhere): Hostinger KVM does not reliably support kexec. `nixos-infect` converts running Ubuntu/Debian to NixOS over SSH without rescue mode.

   ```bash
   curl https://raw.githubusercontent.com/elitak/nixos-infect/master/nixos-infect | \
     NIX_CHANNEL=nixos-24.11 bash 2>&1 | tee /tmp/infect.log
   ```

2. **`disko.nix` disk device**: use `/dev/vda` (Hostinger VirtIO block device), not `/dev/sda`.

3. **After year**: migrate to Hetzner via `nixos-anywhere` + disko for a clean install.
   Data migration: `pg_dump` on old VPS → `rsync` → `pg_restore` on new VPS.

---

## Part L: AgentFlow as Separate GitHub Project (Added 2026-02-23)

### Context

Part 4 sketched `@agentflow/core` as an npm package concept. This part makes it concrete: a
separate private GitHub repository, with an explicit file structure, PostgreSQL schema separation,
and a phased migration path that does not block the PostgreSQL migration.

### Explicit Repository Structure

```
agentflow/                          # separate private GitHub repo
  package.json                      # name: "@agentflow/core", version: "0.1.0"
  src/
    agents/                         # ALL agent files moved from 333Method/src/agents/
      base-agent.js
      developer.js, qa.js, security.js, architect.js
      monitor.js, triage.js, runner.js
      run-single.js
      utils/                        # file-operations, task-manager, test-runner,
      |                             #   claude-api, structured-logger, slo-tracker, etc.
      contexts/                     # base.md, developer.md, qa.md, architect.md, etc.
      workflows/                    # bug-fix.js, feature.js, refactor.js
    cli/
      agent-manager.js              # moved from 333Method/src/cli/agent-manager.js
  db/
    schema-agentflow.sql            # agent_tasks, agent_messages, agent_machines tables only
    migrations/                     # agentflow-specific migrations (own sequence)
  tests/
    agents/                         # agent tests moved from 333Method/tests/agents/
  docs/
    agent-system.md                 # moved from 333Method/docs/06-automation/agent-system.md
  README.md
```

### How 333Method Consumes AgentFlow

```bash
# In 333Method repo:
npm install @agentflow/core
```

```javascript
// src/agents/index.js — thin wrapper in 333Method
import { AgentRunner } from '@agentflow/core';

const runner = new AgentRunner({
  databaseUrl: process.env.DATABASE_URL,
  redisUrl: process.env.REDIS_URL,
  projectRoot: process.cwd(),
  projectName: '333Method',
});
```

333Method's `agent_tasks` table schema is provided by AgentFlow's migration (applied once during
setup). The `npm run agent:*` CLI commands remain in 333Method, delegating to `@agentflow/core`.

### Database Strategy: Shared VPS PostgreSQL, Separate Schemas

Rather than two PostgreSQL instances, use PostgreSQL schemas within the single Hostinger VPS:

```sql
-- Single VPS PostgreSQL, two schemas:
CREATE SCHEMA pipeline;   -- 333Method: sites, outreaches, conversations, config, etc.
CREATE SCHEMA agentflow;  -- agent_tasks, agent_machines, agent_messages, etc.

-- Each app connects with its own role scoped to its schema:
CREATE ROLE pipeline_app   LOGIN;
CREATE ROLE agentflow_app  LOGIN;
GRANT USAGE ON SCHEMA pipeline  TO pipeline_app;
GRANT USAGE ON SCHEMA agentflow TO agentflow_app;

-- AgentFlow Monitor agent can read pipeline status (read-only):
GRANT SELECT ON pipeline.sites        TO agentflow_app;
GRANT SELECT ON pipeline.outreaches   TO agentflow_app;
```

### Self-Monitoring: One Instance, Two Projects

```javascript
// agentflow VPS service config
const runner = new AgentRunner({
  projects: [
    { name: '333Method', root: '/opt/333method', logPattern: 'logs/*.log' },
    { name: 'AgentFlow', root: '/opt/agentflow', logPattern: 'logs/*.log' },
  ],
});
```

- **Monitor agent** scans both log directories
- **Triage agent** classifies errors, tagging by originating project
- **Developer agent** creates fix PRs in the correct repo
- **QA agent** runs the appropriate test suite (`npm test` in each project)

### Migration Path (Does Not Block PostgreSQL Migration)

| Phase | When                       | Action                                                                |
| ----- | -------------------------- | --------------------------------------------------------------------- |
| 1     | Now                        | Keep agents in 333Method — just document this plan                    |
| 2     | After PostgreSQL migration | Extract `src/agents/` → new `agentflow/` GitHub repo                  |
| 3     | After extraction           | Publish `@agentflow/core` to GitHub Packages (private npm registry)   |
| 4     | After 333Method imports it | Run both projects under a single AgentFlow VPS service                |
| 5     | Optional                   | Open-source if the agent system has standalone value beyond 333Method |

**Phase 2 is the extraction blocker — it requires PostgreSQL to be live first** so that the agent
system is decoupled from SQLite before being split into a separate repo.

### Files in `333Method-infra/` to Add

- `modules/containers-vps.nix` — replaces `modules/containers.nix` for production host
- `modules/containers-worker.nix` — new, imported by `hosts/worker-usb/configuration.nix`
- Update `hosts/production/configuration.nix` to import `containers-vps.nix`
- Update `hosts/worker-usb/configuration.nix` to import `containers-worker.nix`

---

## Part M: IronClaw Replaces OpenClaw (Added 2026-02-24)

### Context

Two discoveries prompted this change:

1. **ClawHavoc supply chain attack** — 1,184 malicious skills compromised OpenClaw's ClawHub
   marketplace (February 2026). An active CVE in OpenClaw's Docker sandbox allows configuration
   injection through skill parameters. The custom audit sidecar (Parts 6–13) **detects** attacks
   but doesn't **prevent** them — IronClaw's WASM sandbox is structurally preventive.

2. **Non-tech colleague social media use case** — colleagues need an easy interface to post social
   media marketing content. IronClaw supports WhatsApp/Telegram/Discord (25+ channels) natively
   with the same chat-based UX as OpenClaw, plus built-in social media tools.

### M1: Why IronClaw's Security Model Supersedes the Audit Sidecar

The custom audit sidecar (Parts 6, 9–11) was built because OpenClaw executes dynamic Node.js code
that can issue arbitrary host syscalls. IronClaw eliminates that attack surface structurally:

| Threat                                 | OpenClaw + audit sidecar               | IronClaw WASM                                                       |
| -------------------------------------- | -------------------------------------- | ------------------------------------------------------------------- |
| Malicious skill disables rsyslog       | Detected in audit log (after the fact) | WASM can't issue `killall` syscall (capability not granted)         |
| Skill reads `/run/secrets`             | auditd captures the open() call        | WASM filesystem capability scoped — `/run/secrets` never accessible |
| Prompt injection → exec arbitrary code | Exec appears in audit log              | WASM sandbox — no host exec capability                              |
| Supply chain skill from ClawHub        | Runs in Docker (host syscall access)   | Runs in WASM sandbox (no host access)                               |
| Credential leak via LLM context        | Protected by Docker Secrets + sops     | Secrets vault: LLM context never contains credentials               |

**The audit sidecar shifts from primary trust mechanism to secondary defence-in-depth.**

### M2: Off-Host Audit Trail Is More Trustworthy with IronClaw

The Better Stack pipeline runs at kernel/host level, completely outside Docker:

```
IronClaw (WASM tool) → WASM runtime (blocks raw syscalls) → ✗ no path to host

NixOS kernel → auditd → rsyslog → Better Stack (TLS port 6514)
```

With IronClaw, rsyslog **cannot** be killed or modified by the agent — even under full prompt
compromise. The WASM capability model blocks it before the syscall layer. The iptables rule
blocking UID 9000 from reaching `in.logs.betterstack.com` remains as belt-and-suspenders.

### M3: containers.nix Changes

Replace `openclaw` container with `ironclaw`:

```nix
# modules/containers-vps.nix (replaces openclaw stanza)
ironclaw = {
  image = "ghcr.io/nearai/ironclaw:latest";
  volumes = [
    "/opt/333method-ironclaw-workspace:/workspace"
    "/opt/333method/src:/app/src:ro"
    "/opt/333method/docs:/app/docs:ro"
  ];
  environment = {
    IRONCLAW_CHANNEL = "telegram";        # WhatsApp or Telegram for colleague interface
    IRONCLAW_SECRETS_DIR = "/run/secrets"; # sops-nix secrets; never passed to LLM
  };
  extraOptions = [ "--network=333method-openclaw-net" "--user=9000:9000" ];
};
```

The `333method-openclaw-net` Docker network name is kept (rename optional); Docker network
isolation remains as defence-in-depth alongside WASM sandboxing.

### M4: monitoring.nix Changes

Simplify auditd rules — keep for defence-in-depth, but remove `connect` syscall tracking
(WASM capability model handles network access before syscall layer):

```nix
# Simplified — connect tracking removed (WASM handles network access)
services.auditd.extraConfig = ''
  -a always,exit -F arch=b64 -S execve -F uid=9000 -k ironclaw_exec
  -a always,exit -F arch=b64 -S openat,creat -F perm=w -F uid=9000 -k ironclaw_writes
'';
```

`services.rsyslog` → Better Stack: **no change** (already switched from Papertrail, commit 62f80de).
`systemd.services.git-watcher` → **no change**.
iptables UID 9000 block → **no change** (update IP from Papertrail to Better Stack, already done).

### M5: Social Media for Non-Tech Colleagues

IronClaw provides the WhatsApp/Telegram chat interface non-tech colleagues already use. Social
media posting is handled via IronClaw's native tool capabilities — no third-party skill marketplace
needed (avoids ClawHub supply chain risk).

**Setup (operator, one-time):**

1. Deploy IronClaw on VPS
2. Create Telegram bot via BotFather (or connect WhatsApp Business number)
3. Add colleagues' phone/Telegram IDs to IronClaw authorized users config
4. Colleagues chat with IronClaw — no new apps, no logins

**Non-tech UX:**

```
Colleague (WhatsApp): "Post this to Instagram and LinkedIn — New product launch!"
IronClaw → posts via social platform APIs
IronClaw → "✅ Posted to Instagram and LinkedIn."
```

### M6: IronClaw Heartbeat Replaces Tier 1/2/3 Cron Watchdogs

IronClaw's **Heartbeat System** runs proactive VPS monitoring on a schedule, replacing the custom
Tier 1/2/3 cron watchdogs from `src/cron.js`:

| Current custom cron                                | IronClaw Heartbeat replacement                                |
| -------------------------------------------------- | ------------------------------------------------------------- |
| Tier 1 (1min): process guardian, pipeline restart  | IronClaw heartbeat: service status, restart if down           |
| Tier 2 (5min): bottleneck detection, blocked tasks | IronClaw: query agent_tasks for stuck/blocked, Telegram alert |
| Tier 3 (30min): Sonnet Overseer LLM health check   | IronClaw: LLM analysis of log patterns                        |
| `audit-log-review.js` daily cron                   | IronClaw: built-in LLM reviews audit events                   |

**Retain in `cron.js`** (business logic, not infrastructure):

- `weeklyRepricing`, `creditMonitor`, `serpKeywords`, `sonnetOverseer` (domain-specific)

**Remove from `cron.js`** (delegated to IronClaw):

- `processGuardian`, `pipelineMonitor` → IronClaw Heartbeat

**IronClaw VPS maintenance tasks (autonomous):**

- Disk space alert when >80% full (Telegram notification)
- PostgreSQL, Redis, pipeline container health checks
- Restic backup verification after each run
- SSL certificate expiry reminders
- Log rotation trigger

### M7: New Secrets Required

Add to `secrets/production.yaml` via `sops secrets/production.yaml`:

```yaml
telegram_bot_token: ENC[...] # from Telegram BotFather
```

Add to `modules/secrets.nix`:

```nix
sops.secrets.telegram_bot_token = { owner = "ironclaw"; mode = "0440"; };
```

### M8: Parts 6–13 Status After IronClaw Switch

| Part                           | Status   | Notes                                                                 |
| ------------------------------ | -------- | --------------------------------------------------------------------- |
| Part 6 (auditd / rsyslog)      | Simplify | Drop `connect` syscall rule; keep execve + writes as defence-in-depth |
| Part 7 (Docker networking)     | Keep     | Network isolation is still good defence-in-depth                      |
| Part 8 (NetBird/WireGuard)     | Keep     | No change                                                             |
| Part 9 (Secrets / sops)        | Keep     | No change                                                             |
| Part 10 (Bootstrap agent)      | Replace  | IronClaw handles bootstrap tasks natively via WASM tools              |
| Part 11 (AI audit review cron) | Replace  | IronClaw built-in LLM reviews audit; keep Part 11 as fallback         |
| Part 12 (NixOS IaC)            | Keep     | No change                                                             |
| Part 13 (Access control)       | Keep     | PostgreSQL views + AppArmor profile rename `openclaw` → `ironclaw`    |

---

## Part N: VPS Configuration via Claude Code SSH + GPT-4o Cross-Check (Added 2026-02-24)

### Context

IronClaw on the VPS cannot `nixos-rebuild switch` the machine it sits on — that would be a
container modifying its host OS, which is both dangerous and structurally blocked by the WASM
sandbox. VPS configuration needs a separate approach with clear oversight.

### Architecture: Propose → Review → Apply → Audit

```
Claude Code (desktop)          VPS (Hostinger)
      │                              │
      │  1. edit infra repo          │
      │  2. git commit               │
      │  3. SSH → nixos-rebuild ─────►  applies config
      │                              │
      │                              ▼
      │                    auditd + git-watcher
      │                              │
      │                              ▼
      │                         Better Stack
      │                              │
      │                         GPT-4o review (daily)
      │                              │
      │                         Human Review dashboard
      │                              ▼
      └──────────── Jason reviews risk flags ◄──────────────┘
```

**Why Claude Code on desktop, not IronClaw on VPS:**

- Claude Code has full codebase context (infra repo + app repo)
- Desktop is the authoritative source for infra repo changes
- SSH session is audited by VPS auditd → Better Stack before Claude Code could alter it
- IronClaw stays in its lane: monitoring, alerting, social media, agent coordination

### N1: SSH Key Setup

**One-time setup (Jason, from host terminal):**

```bash
# Generate SSH key for VPS access (if not already done)
ssh-keygen -t ed25519 -C "claude-code-vps" -f ~/.ssh/vps_ed25519

# Copy public key to VPS during initial provisioning
ssh-copy-id -i ~/.ssh/vps_ed25519.pub root@<vps-ip>
```

Claude Code can use this key from inside its Docker sandbox — `/home/jason/.ssh/` is mounted
read-only in the container. SSH commands work directly:

```bash
ssh -i ~/.ssh/vps_ed25519 admin@<vps-netbird-ip> 'nixos-rebuild switch --flake /opt/333method-infra#production'
```

**Principle of least privilege for SSH:**

- Dedicated `admin` user on VPS (not root) with `sudo nixos-rebuild switch` only
- `AllowUsers admin` in sshd_config (root SSH disabled — already in security.nix)
- SSH only accessible via NetBird WireGuard subnet (public internet SSH blocked post-VPN)

### N2: What Claude Code Can Do via SSH

| Task                | How                                              | Oversight                        |
| ------------------- | ------------------------------------------------ | -------------------------------- |
| Edit infra repo     | Local file edits on desktop                      | git diff visible before commit   |
| Apply NixOS config  | `nixos-rebuild switch --flake .#production`      | auditd captures command + output |
| View service status | `systemctl status docker-*`                      | Read-only, no audit needed       |
| View logs           | `journalctl -u docker-ironclaw --since "1h ago"` | Read-only                        |
| Restart a service   | `systemctl restart docker-pipeline`              | auditd captures                  |
| Emergency rollback  | `nixos-rebuild switch --rollback`                | auditd captures                  |

**Claude Code will NOT do via SSH (always confirms first):**

- Destructive operations: `rm -rf`, `docker system prune`, `DROP TABLE`
- Modifying auditd/rsyslog config (would undermine audit trail)
- Modifying iptables rules that block UID 9000

### N3: GPT-4o Cross-Check (Independent Audit Review)

The existing Part 11 (AI Audit Review Pipeline) handles this. Key independence principle:

- **Claude Code** (Claude 3.5/4.x) performs the work
- **GPT-4o** (OpenAI) reviews the audit trail of what was done
- Different vendors: a Claude prompt injection cannot compromise the GPT-4o review

**What GPT-4o looks for in SSH sessions:**

```
Review prompt additions for SSH audit context:
- SSH login events from Claude Code (compare expected key fingerprint)
- Commands run during SSH session (auditd execve with parent sshd)
- Files modified post-SSH (git-watcher commits show exact diff)
- Any nixos-rebuild invocations (expected vs unexpected timing)
- Discrepancy: Claude Code says it did X, but audit log shows it did Y
```

**Audit trail for SSH sessions:**

1. `auditd` captures all `execve` calls from the SSH session user
2. `git-watcher` commits any file changes with exact diff to private audit repo
3. Both streams → Better Stack → GPT-4o reviews daily → risk score → Human Review dashboard

**Risk scoring additions for SSH:**

- `risk_level 1`: Routine `nixos-rebuild switch` matching recent infra repo commit
- `risk_level 2`: Service restart, log viewing
- `risk_level 3`: Config file changes not in infra repo (untracked changes)
- `risk_level 4`: auditd/rsyslog/iptables touched
- `risk_level 5`: Secrets files accessed, unexpected outbound connections from SSH session

### N4: Rotate Audit Review Model Monthly

To prevent any single AI vendor from being a single point of trust:

```javascript
// src/cron/audit-log-review.js
const MONTHLY_ROTATION = {
  0: 'openai/gpt-4o', // January, March, May...
  1: 'x-ai/grok-2', // February, April, June...
};
const model = MONTHLY_ROTATION[new Date().getMonth() % 2];
```

Configured via `AUDIT_REVIEW_MODEL` in `.env` — override rotation if preferred.

### N5: secrets.nix Update

Add Telegram bot token for IronClaw's colleague interface:

```nix
# modules/secrets.nix addition
sops.secrets.telegram_bot_token = { owner = "ironclaw"; mode = "0440"; };
```

Add to `secrets/production.yaml` via `SOPS_AGE_KEY_FILE=~/.age/infra.key sops secrets/production.yaml`:

```yaml
telegram_bot_token: CHANGE_ME # from Telegram BotFather after IronClaw deployed
```

---

## Part O: Agent Security Hardening (Added 2026-03-03)

These recommendations apply to IronClaw, any future OpenClaw-derived agent, and any AI agent runtime with tool execution capability. They complement the WASM sandbox model (Part M) with operational and procedural controls that WASM alone cannot provide.

### O1: Isolated Execution Environment

Run agents in a sandboxed VM or container on an isolated host. **Default-deny egress** with a tightly scoped allowlist:

```nix
# security.nix — iptables egress allowlist for IronClaw (UID 9000)
# All outbound blocked except:
#   - Anthropic API (api.anthropic.com :443)
#   - OpenRouter (openrouter.ai :443)
#   - NetBird control plane (app.netbird.io :443)
#   - VPS-internal Docker network (172.17.0.0/16)
```

Never run agent containers on the same host as production secrets, the pipeline database, or sensitive customer data. If a breach occurs, blast radius is scoped to the agent container only.

**Current posture:** IronClaw uses WASM sandbox (no raw host syscalls) + UID 9000 iptables block (defence-in-depth). ✅

### O2: Non-Human Service Identities, Least Privilege, Short Token Lifetimes

Agents must never share human credentials or long-lived API keys:

| Control                     | Implementation                                                                       |
| --------------------------- | ------------------------------------------------------------------------------------ |
| Separate API keys per agent | IronClaw gets its own OpenRouter/Anthropic key, not the pipeline's key               |
| No direct DB access         | Agent queries via an API or read-only replica — never direct SQLite file access      |
| Short token lifetimes       | Rotate agent API keys monthly; use `sops` for storage (see Part 9)                   |
| No production secrets       | Agent context never receives raw credentials; secrets injected at WASM boundary only |
| Scoped file permissions     | Agent writes only to `/tmp/ironclaw-*`; `/opt/333method/db/` is read-only mounted    |

**Current posture:** Secrets vault (Part 9) and WASM execution boundary already enforce this. Agent key rotation and per-agent API keys are pending. ⚠️

**TODO:** Create a separate `IRONCLAW_OPENROUTER_API_KEY` in `.env` with a dedicated OpenRouter sub-account — prevents an agent compromise from using the pipeline's rate limit or billing.

### O3: Skill/Extension Installation as Code Review

Installing a skill, plugin, or tool extension into an agent runtime is equivalent to merging untrusted code into a privileged environment. Treat it that way:

- **Restrict registries:** Only allow skills from sources you control or have explicitly audited. Disable any auto-install from shared marketplaces (ClawHub, MCP registries) by default.
- **Validate provenance:** Pin skill versions. If the registry supports it, verify signatures. Review the skill's declared capabilities (network, filesystem, subprocess) before enabling.
- **Monitor for rare or newly seen skills:** Alert if an agent attempts to load a skill not in the approved list. Log the skill name, version, and invocation context.
- **Treat first-run of any skill as a code review gate** — a human should approve new skills before they run in production.

**Current posture:** IronClaw WASM sandbox limits what skills can do at the syscall level, but does not prevent a malicious skill from exfiltrating data via allowed network channels. Allowlist-based skill approval is not yet implemented. ⚠️

**TODO:** Implement an approved-skills list in IronClaw config; log any invocation of an unlisted tool to `audit-log-review.js` pipeline.

### O4: Periodic Review of Agent Memory and State

Agents with persistent memory (conversation history, learned instructions, notes files) are vulnerable to **durable prompt injection**: malicious content ingested from an untrusted source embeds instructions that persist across sessions.

**Review triggers:**

- After the agent processes any untrusted external content (scraped websites, inbound emails, public feeds)
- Weekly baseline review of agent state/memory snapshots
- After any anomalous behaviour (unexpected tool calls, unusual outbound requests, changed response patterns)

**Practical implementation:**

```bash
# Snapshot IronClaw memory/state weekly (add to backup.nix rotation)
# /opt/ironclaw/memory/ → /opt/backups/ironclaw-memory-YYYY-MM-DD.tar.gz

# Review checklist:
# - Any new standing instructions added since last review?
# - Any new approved contacts or phone numbers?
# - Any changes to scheduled tasks or heartbeat config?
# - Tool call history: any calls to unexpected domains or APIs?
```

**Current posture:** No systematic agent memory review process exists yet. ⚠️

**TODO:** Add `ironclaw-memory-review` to the weekly cron schedule; route findings to human review queue.

### O5: Nuke-and-Pave Playbook

Accept that a sufficiently sophisticated compromise may be undetectable until after the fact. Design for fast, complete recovery rather than perfect prevention:

**Non-sensitive state to snapshot (safe to keep off-host):**

- Pipeline database export (sites, outreaches, conversations — no secrets)
- Agent task history (audit value)
- Cron job config (`cron_jobs` table)
- Dashboard cache (`dashboard_cache` — re-computable, but convenient)
- Prompt templates (`prompts/` directory)

**Credential rotation playbook (rehearse quarterly):**

```
1. Generate new credentials for ALL services:
   - OpenRouter (new API key)
   - Anthropic (new API key)
   - Resend (new API key)
   - Twilio (new auth token)
   - ZenRows (new API key)
   - NetBird (revoke all device keys, re-enrol)
   - sops age key (new key, re-encrypt secrets/production.yaml)

2. Rebuild VPS from flake:
   nix run nixpkgs#nixos-anywhere -- --flake .#production root@NEW_IP

3. Restore non-sensitive DB snapshot to new host

4. Restore prompts/, data/, .env (non-secret fields only)

5. Inject new credentials via sops:
   SOPS_AGE_KEY_FILE=~/.age/infra.key sops secrets/production.yaml

6. nixos-rebuild switch

7. Verify: curl https://dashboard.molecool.org/api/v1/overview
```

**RTO target:** < 4 hours from decision to resume (all steps documented, no manual guesswork).

**Current posture:** Backup cron exists (Part backup.nix). No credential rotation playbook written or rehearsed. ⚠️

**TODO:** Write `scripts/credential-rotation-playbook.md`; add a calendar reminder to rehearse quarterly (test the non-sensitive restore path at minimum).

### O6: Real-Time Anti-Malware on Agent and Pipeline Hosts

An up-to-date anti-malware solution running on the VPS catches information stealers, credential harvesting malware, and other threats that operate below the application layer — threats that WASM sandboxing, iptables rules, and audit logs cannot detect:

**Recommended approach for NixOS VPS:**

```nix
# Add to security.nix
services.clamav = {
  daemon.enable = true;
  updater.enable = true;   # freshclam — keeps signatures current
  updater.frequency = 12;  # update twice daily
};
```

**Scan targets (add to weekly cron):**

- `/opt/333method/` — pipeline code and data
- `/opt/ironclaw/` — agent runtime and memory
- `/tmp/` — ephemeral agent working directories
- Newly downloaded skill/extension files before installation

**For the development host (Docker sandbox):** Enable ClamAV or equivalent on the NixOS host; the Docker container mounts `/home/jason/code/` which contains all source code and the agent memory directory.

**Current posture:** No anti-malware running on VPS or development host. ⚠️

**TODO:** Add `services.clamav` to `security.nix` in `333Method-infra`; add a weekly `clamscan` cron job that routes findings to the human review queue.

---

### O: Summary Status

| Control                         | Status                              | Next action                                     |
| ------------------------------- | ----------------------------------- | ----------------------------------------------- |
| Isolated container + egress ACL | ✅ UID 9000 iptables + WASM sandbox | —                                               |
| Per-agent service identities    | ⚠️ Shares pipeline key currently    | Create `IRONCLAW_OPENROUTER_API_KEY`            |
| Short token lifetimes           | ⚠️ No rotation schedule             | Add to quarterly ops calendar                   |
| Skill allowlisting              | ⚠️ Not implemented                  | Approved-skills list in IronClaw config         |
| Agent memory review             | ⚠️ No process                       | Add weekly cron + human review queue entry      |
| Nuke-and-pave playbook          | ⚠️ No documented playbook           | Write `scripts/credential-rotation-playbook.md` |
| Anti-malware                    | ⚠️ Not running                      | Add `services.clamav` to security.nix           |

---

## Part P: Analytics Dashboard (React + FastAPI)

**Status:** In progress — dashboard-v2/ scaffolded, pages implemented, infra config added.

### Why

The Streamlit dashboard (~3,700 lines Python, 11 pages) re-executes the entire Python process on every page navigation — even with 3-layer caching, this is an inherent Streamlit framework limitation causing sluggish navigation. Replaced with a React SPA (instant client-side routing) + FastAPI backend.

### Branding: Audit&Fix

The dashboard uses the **Audit&Fix** brand identity (see `docs/09-business/auditandfix-brand.md`). Never reference "333 Method" in any user-facing dashboard UI.

**Colour palette (Tailwind overrides in `tailwind.config.js`):**

| Role       | Hex       | Tailwind class      | Usage                             |
| ---------- | --------- | ------------------- | --------------------------------- |
| Primary    | `#1a365d` | `navy-800`          | Sidebar, nav, card headers        |
| Accent     | `#e05d26` | `brand-orange`      | Active nav highlight, CTA buttons |
| Hover      | `#c44d1e` | `brand-orange-dark` | Button hover states               |
| Background | `#0f172a` | `slate-900`         | Page background (dark mode)       |
| Card bg    | `#1e293b` | `slate-800`         | Card/panel backgrounds            |
| Text       | `#e2e8f0` | `slate-200`         | Primary text                      |
| Text muted | `#94a3b8` | `slate-400`         | Labels, captions                  |
| Success    | `#38a169` | `emerald-500`       | Positive metrics, A grades        |
| Warning    | `#fbbf24` | `amber-400`         | Warnings, B grades                |
| Error      | `#f87171` | `red-400`           | Errors, failing metrics           |

**Grade colours** (match PDF report spec):

| Grade   | Colour             |
| ------- | ------------------ |
| A+/A/A- | `#38a169` (green)  |
| B+/B/B- | `#3182ce` (blue)   |
| C       | `#d69e2e` (amber)  |
| D       | `#dd6b20` (orange) |
| E/F     | `#e53e3e` (red)    |

**Logo:** `auditandfix.com/assets/img/logo.svg` — magnifying glass icon (orange `#e05d26` stroke, white tick) + wordmark ("Audit" white, "&" orange, "Fix" white). Designed for dark backgrounds. Rendered at 36px height in sidebar nav. Fallback text: `Audit<span style="color:#e05d26">&</span>Fix`.

**Favicon:** `auditandfix.com/assets/img/favicon.svg` — 32×32 navy square (rx:6), "A&F" lettering (white + orange). Referenced in `index.html`:

```html
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml" />
```

**Typography:** System font stack (no external fonts): `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif`.

**Implementation notes:**

- Copy `auditandfix.com/assets/img/logo.svg` → `dashboard-v2/frontend/public/assets/img/logo.svg`
- Copy `auditandfix.com/assets/img/favicon.svg` → `dashboard-v2/frontend/public/assets/img/favicon.svg`
- Update `Layout.jsx` sidebar to render `logo.svg` (36px height) instead of text
- Update `index.html` `<link rel="icon">` to point to favicon.svg
- Update `tailwind.config.js` to add `brand-orange` and `navy` custom colours
- Update `index.css` to use system font stack

### Architecture

```
[Browser — NetBird VPN]
  → dashboard.molecool.org (Caddy, VPN-only via NetBird ACLs)
    → React SPA (Vite build, static files served by Caddy)
      → GET /api/v1/...  →  FastAPI (uvicorn, port 8502)
                              → reads PostgreSQL (post-migration)
                              → falls back to dashboard_cache table on cache miss
                              → SQLite fallback during migration period
```

**PostgreSQL integration:**

- FastAPI connects to PostgreSQL (`DATABASE_URL` env var) once migrated
- `dashboard_cache` table migrates to PostgreSQL alongside everything else
- `precompute-dashboard.js` continues populating it every 10 minutes (no changes)
- SQLite fallback retained during migration period via `DATABASE_URL` env var

### Page Structure (7 pages, down from 11)

| New Page          | Replaces                     | Notes                                                 |
| ----------------- | ---------------------------- | ----------------------------------------------------- |
| **Overview**      | Overview + Pipeline          | Merged; includes cost forecast + profitability panel  |
| **Outreach**      | Outreach                     | Delivery funnel, response rates, sales, LLM costs     |
| **Conversations** | Conversations                | Threaded messages, sentiment, reply box — always live |
| **Operations**    | System Health + Cron Jobs    | Tabbed: CronJobs \| SystemHealth                      |
| **Quality**       | Code Coverage + Agent System | Tabbed: AgentSystem \| CodeCoverage                   |
| **Compliance**    | Compliance + Prompt Learning | Tabbed: Legal \| PromptLearning                       |
| **Review**        | Human Review                 | Action items, pending outreaches, failing sites       |

### Cost Forecast Feature

Overview page includes a **Cost Forecast & Profitability panel** sourced from business plan constants and live `llm_usage` + `outreaches` data:

- **API Cost/Day** — 30-day rolling average from `llm_usage.estimated_cost`
- **Pipeline Cost (pending)** — sum of `pending_sites × avg_cost_per_stage`
- **Monthly Revenue** — actual sales × avg deal value (last 30 days)
- **Monthly Profit** — revenue − COGS ($2/sale) − fixed opex ($306/mo) − API costs
- **Break-even status** — how many more sales needed at current fixed costs
- **Projections** — based on BP assumptions: 500 outreach/mo × 2% response × 20% conv = 2 sales/mo
- **Cache TTL: 4 days** — `cost_forecast` cache key set with 4-day expiry in `precompute-dashboard.js`

Business plan constants baked into `Overview.jsx` `BP` object (update when BP changes):

- `avg_price_aud: 297`, `cogs_per_sale: 2.00`, `fixed_monthly: 306`
- `personal_monthly_needed: 9207`

### Key Files

| File                                                    | Purpose                                            |
| ------------------------------------------------------- | -------------------------------------------------- |
| `dashboard-v2/backend/main.py`                          | FastAPI — 7 endpoints + cost_forecast in /overview |
| `dashboard-v2/backend/db.py`                            | asyncpg (PG) + aiosqlite (SQLite) fallback         |
| `dashboard-v2/backend/cache.py`                         | Reads `dashboard_cache` table                      |
| `dashboard-v2/backend/requirements.txt`                 | fastapi, uvicorn, asyncpg, aiosqlite               |
| `dashboard-v2/frontend/src/pages/Overview.jsx`          | CostForecast component + merged pipeline           |
| `dashboard-v2/frontend/src/pages/Conversations.jsx`     | Live data, reply box, thread view                  |
| `dashboard-v2/frontend/src/components/charts/index.jsx` | Recharts wrappers (dark palette)                   |
| `dashboard-v2/frontend/src/components/Layout.jsx`       | Sidebar nav with Audit&Fix logo (36px)             |
| `src/cron/precompute-dashboard.js`                      | Added `cacheCostForecast()` (4-day TTL)            |
| `auditandfix.com/assets/img/logo.svg`                   | Audit&Fix logo (dark bg variant)                   |
| `auditandfix.com/assets/img/favicon.svg`                | Audit&Fix favicon (32×32 navy square)              |
| `docs/09-business/auditandfix-brand.md`                 | Full brand guide (colours, typography, voice)      |
| `333Method-infra/modules/caddy.nix`                     | Caddy vhost: dashboard.molecool.org                |
| `333Method-infra/modules/containers.nix`                | `dashboard-api` OCI container (port 8502)          |
| `333Method-infra/flake.nix`                             | Added caddy.nix to production modules              |

### Deployment (Phase 5 — after PostgreSQL migration)

1. Build frontend: `npm run dashboard:v2-build`
2. Copy build to VPS: `rsync -a dashboard-v2/frontend/dist/ root@vps:/opt/333method/dashboard-v2/frontend/dist/`
3. `nixos-rebuild switch` on VPS (starts `dashboard-api` container + Caddy)
4. Verify: `curl https://dashboard.molecool.org/api/v1/overview` from NetBird peer
5. Stop Streamlit: `systemctl stop docker-dashboard`
6. Once PostgreSQL migrated: set `DATABASE_URL=postgresql://...` in `/opt/333method/.env` and restart `docker-dashboard-api`

---

## Part Q: Operational Intelligence Dashboard Pages (Added 2026-03-04)

**Source:** Analysis of recurring user questions during AFK monitoring sessions (cycles 37–40 and prior sessions). These are the questions Jason consistently asks that are NOT currently answered by a single dashboard view.

### Background: Question Pattern Analysis

From reviewing AFK monitoring transcripts, 8 recurring question patterns emerged. Each maps to a gap in the current Streamlit dashboard:

| Question Pattern                                                   | Current Gap                                            | Proposed Page/Widget                                           |
| ------------------------------------------------------------------ | ------------------------------------------------------ | -------------------------------------------------------------- |
| "Is X stage running? Why haven't these been sent?"                 | Must run `npm run status` manually                     | Pipeline Health — queue depths + last-run age                  |
| "47min per proposal is ridiculous, should be 30s"                  | Performance Trends page only shows batch durations     | Per-Stage Performance — p50/p95 per site, trend vs 24h         |
| "Does ENRICHMENT_CONCURRENCY use Playwright? Why not both 8?"      | No visibility into effective vs configured concurrency | Concurrency Monitor — live adaptive vs ceiling                 |
| "I didn't approve 2230 outreaches — are they really all sending?"  | Can check DB but no quick trust view                   | Outreach Trust — approved-unsent breakdown + config validation |
| "Are 400 errors wasting $8/day?"                                   | Error rates spread across 8 log files                  | API Health — error rate by service + estimated waste           |
| "Why didn't the monitor catch the browser loop?"                   | No monitoring-of-monitoring view                       | Monitoring Audit — what Tier 1/2 caught vs missed              |
| "OUTREACH_SKIP_METHODS should disable form/x/linkedin — are they?" | No config validation view                              | Config Validator — live env flags with green/red indicators    |
| "Is `npm run status` counting this correctly?"                     | Inconsistencies only discovered manually               | Metrics Consistency — cross-check outreach counts              |

---

### New Dashboard Pages

All pages below are additions to the existing Streamlit dashboard (`dashboard/pages/`) and the planned React/FastAPI dashboard v2 (`dashboard-v2/`). They use pre-computed cache keys (4-min TTL) populated by `src/cron/precompute-dashboard.js`, never direct DB queries from the frontend.

---

#### Q.1 Pipeline Health Page

**File:** `dashboard/pages/10_🔄_Pipeline_Health.py` (Streamlit) + `dashboard-v2/frontend/src/pages/PipelineHealth.jsx`

**Primary question answered:** "What's stuck? What's actively flowing?"

**Widgets:**

1. **Stage Queue Depth** — horizontal bar chart, sites per status in pipeline order:
   `found → assets_captured → scored → rescored → enriched → proposals_drafted → outreach_sent`
   - Color: green (< 1h old), yellow (1–4h), red (> 4h)
   - Shows `new1h` delta beside each bar

2. **Stage Last-Run Timestamps** — table: stage name, last_processed_at, age (minutes), status indicator
   - Red if last run > 2× the stage's expected cycle time
   - Pulled from `pipeline_metrics` table (last successful batch per stage)

3. **Active SKIP_STAGES Banner** — top-of-page red banner when `SKIP_STAGES` env var is set:
   `⚠️ SKIP_STAGES=proposals,outreach — pipeline paused after enrich`

4. **Stuck Sites** — count of sites with `updated_at < now() - 4h` per stage (threshold configurable)

5. **Retry Queue** — count of `failing` sites draining back to `found` (via `retryFailingSites()`)

**Backend endpoint:** `GET /api/v1/pipeline-health`

```python
# Precomputed cache key: 'pipeline_health' (4-min TTL)
{
  "stage_depths": [{"stage": str, "count": int, "new_1h": int, "max_age_minutes": int}],
  "last_run": [{"stage": str, "last_at": str, "age_minutes": int, "healthy": bool}],
  "skip_stages": str | null,          # from env/DB config
  "stuck_sites": [{"stage": str, "count": int}],
  "retry_queue_size": int
}
```

---

#### Q.2 Per-Stage Performance Page

**File:** `dashboard/pages/11_⚡_Performance.py` + `dashboard-v2/frontend/src/pages/Performance.jsx`

**Primary question answered:** "Is each stage getting faster or slower? What's the per-site time?"

**Widgets:**

1. **Per-Site Duration Table** — one row per stage, columns: p50 / p95 / avg duration per site (seconds), trend arrow vs 24h ago
   - Derived from `pipeline_metrics`: `AVG(CAST(duration_ms AS REAL)/NULLIF(sites_processed,0)/1000.0)`
   - Highlights red if p95 > user-defined threshold (configurable in DB config table)
   - Default thresholds: scoring 5s, enrich 30s, proposals 60s, assets 10s

2. **30-min Rolling Throughput Chart** — sites/minute per stage, last 4 hours (Recharts/Plotly line chart)

3. **Stage Bottleneck Indicator** — auto-detects which stage has the highest per-site time and shows a callout: `⚠️ Bottleneck: proposals (42s/site, threshold 60s)`

4. **Concurrency Context** — shows current BROWSER_CONCURRENCY, ENRICHMENT_CONCURRENCY, SCORING_CONCURRENCY values alongside the performance metrics so the user can correlate

**Backend endpoint:** `GET /api/v1/performance`

```python
# Precomputed cache key: 'stage_performance' (4-min TTL)
{
  "stages": [{
    "stage": str,
    "p50_per_site_s": float,
    "p95_per_site_s": float,
    "avg_per_site_s": float,
    "trend_24h": float,       # positive = getting slower
    "threshold_s": float,
    "over_threshold": bool
  }],
  "bottleneck_stage": str | null,
  "concurrency": {"browser": int, "enrichment": int, "scoring": int}
}
```

---

#### Q.3 Concurrency Monitor Widget

**Location:** Embedded in Performance page (Q.2) and System Health page sidebar

**Primary question answered:** "What's the effective concurrency vs configured? Is adaptive throttling active?"

**Widgets:**

1. **Concurrency Gauges** — for each browser stage (assets, enrich):
   - Needle gauge: configured ceiling (env var) vs effective (last sampled adaptive value)
   - Shows memory floor trigger: "⚠️ Memory floor active (< 768MB free) — throttled to 1"

2. **Adaptive Thresholds Summary** — table: load avg (1min/5min/15min), free memory MB, EASE_LOAD threshold, MAX_LOAD threshold, screen-on status

3. **Concurrency History** — 1-hour sparkline of effective concurrency for assets + enrich (requires `system_health` metric polling to store adaptive values)

**New `system_health` metrics to collect** (add to `scripts/collect-system-metrics.js`):

- `browser_concurrency_effective` — result of `getAdaptiveConcurrencyFast()` for assets
- `enrichment_concurrency_effective` — result of `getAdaptiveConcurrencyFast()` for enrich
- `adaptive_load_avg_1min`, `adaptive_free_mem_mb`

---

#### Q.4 Outreach Trust Panel

**File:** `dashboard/pages/12_📤_Outreach_Trust.py` + widget embedded in Overview page

**Primary question answered:** "What's approved but unsent? Is anything sending when it shouldn't be?"

**Widgets:**

1. **Approved-Unsent Breakdown** — table by channel (email, sms, form, linkedin, x):
   - Count of approved outreaches not yet sent
   - Age of oldest approved outreach (hours since approval)
   - Last sent timestamp per channel
   - Red indicator if channel is in `OUTREACH_SKIP_METHODS`

2. **Active Config Flags** — inline config validator showing:
   - `SKIP_STAGES`: value or "none" (green)
   - `OUTREACH_SKIP_METHODS`: list of disabled channels (red badges) or "none" (green)
   - `ENABLE_VISION`: true/false
   - `SCORING_CONCURRENCY`, `ENRICHMENT_CONCURRENCY`, `BROWSER_CONCURRENCY`: current values

3. **3-Day Cooldown Queue** — count of sites blocked by `last_outreach_at` cooldown (72h rule): `N sites eligible but cooling down`

4. **Send Rate Timeline** — bar chart: outreaches sent per day (last 14 days), overlaid with approval events

**Backend endpoint:** `GET /api/v1/outreach-trust`

```python
{
  "by_channel": [{
    "channel": str,
    "approved_unsent": int,
    "oldest_approved_age_hours": float,
    "last_sent_at": str | null,
    "skipped_by_config": bool
  }],
  "config_flags": {"skip_stages": str, "outreach_skip_methods": str, "enable_vision": bool, ...},
  "cooldown_blocked_count": int
}
```

---

#### Q.5 API Health & Cost Page

**File:** `dashboard/pages/13_💰_API_Health.py` + `dashboard-v2/frontend/src/pages/ApiHealth.jsx`

**Primary question answered:** "What's the error rate per API? How much is being wasted on failures?"

**Widgets:**

1. **Error Rate by Service** — table: service (ZenRows, OpenRouter, Resend, Twilio), error count (last 30min), error rate %, last error message
   - Red if > 10%, orange if > 5%
   - "No results found" ZenRows errors shown separately (benign) vs 429/500 errors (actionable)

2. **Wasted Cost Estimate** — calculated from incomplete LLM responses and rate-limit retries:
   - `incomplete_llm_count × avg_tokens × cost_per_token` (from `pipeline_metrics` + `agent_llm_usage`)
   - Shows daily waste in USD: `Est. wasted today: $X.XX (N incomplete responses)`

3. **Rate Limit Status** — live view of `logs/rate-limits.json`:
   - Each API: status (clear/limited), reset time if limited, stages currently paused

4. **Credit Balance** — OpenRouter balance + threshold alert (from `openrouter_credit_log`)

5. **Cost Trend** — 14-day rolling API cost per day (scoring + enrichment + proposals), breakdown by stage

**Backend endpoint:** `GET /api/v1/api-health`

```python
{
  "error_rates": [{"service": str, "errors_30min": int, "rate_pct": float, "last_error": str}],
  "wasted_cost_usd_today": float,
  "rate_limits": [{"api": str, "limited": bool, "reset_at": str | null}],
  "credit_balance_usd": float,
  "cost_trend": [{"date": str, "cost_usd": float}]
}
```

---

#### Q.6 Monitoring Audit Widget

**Location:** Embedded in System Health page (existing `dashboard/pages/6_🖥️_System_Health.py`)

**Primary question answered:** "Did the watchers catch what they were supposed to catch? What did they miss?"

**Widgets:**

1. **Tier 1 (Cron) Performance** — table: cron job name, last run, last duration, issues detected vs resolved (from `cron_jobs` + `system_health` table)

2. **Tier 2 (Agent) Outcomes** — last 24h:
   - Tasks created by monitor agent: N
   - Tasks resolved by developer/QA: N
   - Tasks failed or timed out: N
   - Ratio: `resolved / created` — red if < 50%

3. **Blind Spots Detected (Tier 3)** — manual log: issues found by Claude Code AFK monitoring that were NOT caught by Tier 1/2. Written to `system_health` table by the AFK check script with `metric_name='tier3_blind_spot'`.
   - Displays as timeline: date, issue description, fix commit

4. **AFK Check History** — table of last 10 AFK check cycles: timestamp, issues found (0 = clean), fix applied (commit hash if any)

**New `system_health` records** (written by `scripts/monitoring-checks.sh` or manually):

- `metric_name='tier3_blind_spot'`, `metric_value=1`, `details_json={"issue": str, "fix_commit": str}`
- `metric_name='afk_check_cycle'`, `metric_value=N_issues_found`, `details_json={"cycle": int}`

---

#### Q.7 Config Validator Widget

**Location:** Sticky sidebar widget in all dashboard pages (Streamlit sidebar + React Layout.jsx header)

**Primary question answered:** "Are my config flags doing what I think they're doing?"

**Layout:** Compact 2-column grid of flag badges, always visible.

| Flag                     | Value                       | Indicator            |
| ------------------------ | --------------------------- | -------------------- |
| `SKIP_STAGES`            | none / "proposals,outreach" | 🟢 / 🔴              |
| `OUTREACH_SKIP_METHODS`  | none / "form,x,linkedin"    | 🟢 / 🔴 (per method) |
| `ENABLE_VISION`          | true / false                | 🟢 / 🟡              |
| `BROWSER_CONCURRENCY`    | 8 (ceiling) / 3 (effective) | 🟢                   |
| `ENRICHMENT_CONCURRENCY` | 8 (ceiling) / 5 (effective) | 🟢                   |
| `SCORING_CONCURRENCY`    | 2                           | 🟢                   |
| `AGENT_SYSTEM_ENABLED`   | true / false                | 🟢 / 🔴              |
| Pipeline service         | running / stopped           | 🟢 / 🔴              |
| Cron timer               | active / dead               | 🟢 / 🔴              |

**Implementation:** Config flags read from `config` DB table (where stored) + env vars at server startup. Effective concurrency from `system_health` table (last sampled). Service status from `cron_jobs` table last-run timestamps.

---

#### Q.8 Metrics Consistency Check

**Location:** Footer of Overview page + daily agent monitor check

**Primary question answered:** "Do all my metrics agree with each other?"

**Cross-checks run at precompute time:**

| Check                | Query A                               | Query B                                     | Pass Condition   |
| -------------------- | ------------------------------------- | ------------------------------------------- | ---------------- |
| Outreach sent count  | `outreaches WHERE status='sent'`      | `outreaches WHERE delivered_at IS NOT NULL` | Within 5%        |
| Approved vs pipeline | `outreaches WHERE status='approved'`  | `sites WHERE status='proposals_drafted'`    | Difference < 100 |
| Sites in pipeline    | Sum of all non-terminal status counts | Total sites minus ignore + outreach_sent    | Exact match      |
| Cron schedule        | `cron_jobs.last_run`                  | `system_health` last metric timestamp       | < 2× interval    |

**Display:** Green checkmark if all pass. Expandable "Inconsistencies found" section if any fail, with SQL queries to diagnose.

**Backend endpoint:** `GET /api/v1/metrics-consistency`

```python
{
  "checks": [{
    "name": str,
    "passed": bool,
    "value_a": int,
    "value_b": int,
    "delta_pct": float,
    "description": str
  }],
  "all_passed": bool
}
```

---

### Implementation Notes

**Data sources:** All new pages use existing tables (`pipeline_metrics`, `system_health`, `agent_tasks`, `outreaches`, `cron_jobs`, `openrouter_credit_log`). No new migrations required for Q.1–Q.5, Q.7–Q.8.

**Q.6 requires two new `system_health` record types** written by `scripts/monitoring-checks.sh`:

```bash
# Add to end of monitoring-checks.sh after each cycle:
sqlite3 db/sites.db "INSERT INTO system_health (metric_name, metric_value, details_json, recorded_at)
  VALUES ('afk_check_cycle', $ISSUES_FOUND, '{\"cycle\": $CYCLE_NUM}', datetime('now'))"
```

**Q.3 requires new metrics** in `scripts/collect-system-metrics.js`:

```javascript
// Add alongside existing CPU/memory collection:
const browserConcurrency = getAdaptiveConcurrencyFast(
  1,
  parseInt(process.env.BROWSER_CONCURRENCY || '8'),
  'BROWSER_CONCURRENCY'
);
const enrichConcurrency = getAdaptiveConcurrencyFast(
  1,
  parseInt(process.env.ENRICHMENT_CONCURRENCY || '8'),
  'ENRICHMENT_CONCURRENCY'
);
db.prepare('INSERT INTO system_health (metric_name, metric_value) VALUES (?, ?)').run(
  'browser_concurrency_effective',
  browserConcurrency
);
```

**Precompute integration:** Add new cache keys to `src/cron/precompute-dashboard.js`:

- `pipeline_health` (4-min TTL)
- `stage_performance` (4-min TTL)
- `outreach_trust` (4-min TTL)
- `api_health` (4-min TTL)
- `metrics_consistency` (15-min TTL)

**Phase placement:** Implement Q.1–Q.4 as Streamlit additions now (no infrastructure dependency). Q.5–Q.8 can be added alongside or as part of the Part P React/FastAPI dashboard v2 build. Q.4 Config Validator sidebar widget is the highest-value single addition (answers 3 of the 8 recurring questions at once).

**Priority order:**

1. Q.4 Config Validator sidebar (answers "are my flags doing what I think?" in one glance)
2. Q.1 Pipeline Health (answers "what's stuck?" without running `npm run status`)
3. Q.5 API Health & Cost (answers "how much is being wasted?")
4. Q.2 Per-Stage Performance (answers "is it getting faster or slower?")
5. Q.8 Metrics Consistency (builds trust in all other dashboard numbers)
6. Q.3 Concurrency Monitor (useful but already partially visible in System Health)
7. Q.6 Monitoring Audit (requires new monitoring-checks.sh instrumentation)

---

## Part 20: ~~LLM Proxy — LiteLLM Gateway~~ [OBSOLETE — Claude Max]

> **Superseded (2026-03-10):** Same rationale as Phase 0. Claude Max subscription eliminates the need for an LLM proxy layer. The 1,000+ lines below are archived for reference only — do not implement.

<details><summary>Original Part 20 content (archived)</summary>

**Added:** 2026-03-05 | **Updated:** 2026-03-05

The LLM Proxy is the single gateway for all LLM traffic. Rather than building from scratch, we
deploy **LiteLLM** (MIT-licensed, 20k+ GitHub stars, 100+ providers) as the core gateway and
build only the ~30% it doesn't cover as thin layers on top.

### §20.1 Build vs Buy Analysis

**Problem statement (discovered 2026-03-04):** $1,871 in unexpected OpenRouter spend. Root causes:

1. Application code held API keys → could call providers directly, bypassing tracking
2. Model selection hardcoded per module → no central optimisation when cheaper models appear
3. Budget enforcement scattered across modules → easy to miss in new code
4. Single provider (OpenRouter) → no ability to exploit cheaper alternatives or subscriptions

**LiteLLM covers ~70% of requirements out of the box:**

| Capability                      | LiteLLM (free) | We Build | Notes                                           |
| ------------------------------- | -------------- | -------- | ----------------------------------------------- |
| OpenAI-compatible API           | YES            |          | Core feature, battle-tested                     |
| 100+ provider routing           | YES            |          | OR, Anthropic, Groq, Together, DeepInfra, etc.  |
| Budget enforcement              | YES            |          | Daily/weekly/monthly caps per key/team/tag      |
| Usage tracking + cost           | YES            |          | Per-request token counting and cost calculation |
| Virtual keys (key isolation)    | YES            |          | App gets proxy key, real keys stay on LiteLLM   |
| Cross-provider model mapping    | YES            |          | `anthropic/claude-sonnet-4.6` prefix pattern    |
| Complexity-based routing        | YES            |          | SIMPLE/MEDIUM/COMPLEX/REASONING auto-classify   |
| Cost/latency-based routing      | YES            |          | Route to cheapest or fastest qualifying model   |
| PII scrubbing (Presidio)        | YES            |          | 12+ languages, configurable entity types        |
| Rate limit handling             | YES            |          | Auto-retry, cooldown, provider fallback         |
| **Success feedback loop**       |                | YES      | Callers report accuracy → proxy adapts routing  |
| **A/B testing engine**          |                | YES      | Split traffic, measure bang-for-buck, promote   |
| **Secret detection**            |                | YES      | API keys, high-entropy strings, JWT patterns    |
| **Subscription drain priority** |                | PARTIAL  | Abacus.ai unlimited tier → paid providers       |
| **Pipeline workload taxonomy**  |                | YES      | Our 9 workload types mapped to LiteLLM routing  |

**Other projects considered and rejected:**

- **TensorZero** (Apache 2.0, Rust) — Best feedback/A/B testing, but no budget enforcement or PII scrubbing. Would need LiteLLM anyway for provider routing. Too complex to run both.
- **Portkey** (MIT gateway) — Similar to LiteLLM but less mature routing. Node.js (lighter) but fewer providers. No feedback loops.
- **Kong AI Gateway** (Apache 2.0) — Enterprise overkill. Best PII scrubbing but massive operational overhead.
- **Cloudflare AI Gateway** — Not self-hostable. All traffic routes through Cloudflare, defeating privacy goals.
- **Martian, Unify** — SaaS-only, not self-hostable.
- **RouteLLM** (Apache 2.0, LMSYS) — Routing-only research project (strong/weak model pair). Could complement LiteLLM but too narrow for our needs.

**Stack compatibility:** LiteLLM is Python + Postgres. Our codebase is Node.js + SQLite. This
is fine — LiteLLM runs as a Docker container (like any other service). The custom extensions
(feedback, A/B testing) are a lightweight Node.js sidecar that reads/writes our existing SQLite
database, keeping the extension logic in the same language as the rest of the pipeline.

### §20.2 Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                   LiteLLM Proxy (Docker, port 4000)                    │
│                                                                         │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐              │
│  │ Virtual  │→│ Budget   │→│ Presidio │→│ Router   │→ 100+ Providers│
│  │ Keys     │  │ Enforce  │  │ PII      │  │ (cost/   │  (OR, Anthro, │
│  │ (auth)   │  │ (daily/  │  │ Scrubber │  │  complex │  Groq, etc.)  │
│  │          │  │  monthly) │  │          │  │  /latency)│              │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘              │
│                                                                         │
│  Built-in: usage tracking, model mapping, rate limit handling,         │
│            provider failover, request logging, /v1/models endpoint     │
└────────────────────────────────┬────────────────────────────────────────┘
                                 │ (Postgres for LiteLLM internal state)
                                 │
┌────────────────────────────────┼────────────────────────────────────────┐
│              Custom Extensions Sidecar (Node.js, port 4001)            │
│                                                                         │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐                 │
│  │ Feedback     │  │ A/B Test     │  │ Secret       │                 │
│  │ Loop         │  │ Engine       │  │ Detection    │                 │
│  │ (§20.6)      │  │ (§20.7)      │  │ (§20.11)     │                 │
│  └──────────────┘  └──────────────┘  └──────────────┘                 │
│                                                                         │
│  POST /v1/feedback   GET /v1/ab-tests   Pre-commit hook               │
│  Accuracy tracking   Traffic splitting   API key pattern matching      │
│  Routing suggestions  Auto-promotion     High-entropy detection        │
└────────────────────────────────┬────────────────────────────────────────┘
                                 │ (SQLite — same db/sites.db)
                                 │
┌────────────────────────────────┼────────────────────────────────────────┐
│                    Application Code (Node.js)                          │
│                                                                         │
│  callLLM() → POST http://localhost:4000/v1/chat/completions            │
│  reportFeedback() → POST http://localhost:4001/v1/feedback             │
│  No API keys. No MODEL_PRICING. No budget checks. Just HTTP calls.    │
└─────────────────────────────────────────────────────────────────────────┘
```

### §20.3 LiteLLM Configuration

**`config/litellm-config.yaml`:**

```yaml
model_list:
  # ── Tier 1: Subscription / Unlimited ──────────────────────────
  # Abacus.ai unlimited tier ($10/mo)
  - model_name: gpt-5-mini-unlimited
    litellm_params:
      model: abacus/gpt-5-mini
      api_key: os.environ/ABACUS_API_KEY
    model_info:
      description: 'Abacus.ai unlimited tier - drain first for simple tasks'

  - model_name: gemini-flash-unlimited
    litellm_params:
      model: abacus/gemini-2.5-flash
      api_key: os.environ/ABACUS_API_KEY

  - model_name: llama-4-unlimited
    litellm_params:
      model: abacus/llama-4
      api_key: os.environ/ABACUS_API_KEY

  # ── Tier 2: Fast + Cheap (Open Source) ────────────────────────
  - model_name: llama-4-scout
    litellm_params:
      model: groq/meta-llama/llama-4-scout-17b-16e-instruct
      api_key: os.environ/GROQ_API_KEY

  - model_name: llama-4-scout-deepinfra
    litellm_params:
      model: deepinfra/meta-llama/Llama-4-Scout-17B-16E-Instruct
      api_key: os.environ/DEEPINFRA_API_KEY

  # ── Tier 3: Workhorse Models ──────────────────────────────────
  - model_name: gpt-4o-mini
    litellm_params:
      model: openrouter/openai/gpt-4o-mini
      api_key: os.environ/OPENROUTER_API_KEY

  - model_name: gemini-2.5-flash
    litellm_params:
      model: openrouter/google/gemini-2.5-flash
      api_key: os.environ/OPENROUTER_API_KEY

  # ── Tier 4: Premium Models ────────────────────────────────────
  - model_name: claude-haiku-4.5
    litellm_params:
      model: anthropic/claude-haiku-4-5-20251001
      api_key: os.environ/ANTHROPIC_API_KEY

  - model_name: claude-sonnet-4.6
    litellm_params:
      model: anthropic/claude-sonnet-4-6-20250514
      api_key: os.environ/ANTHROPIC_API_KEY

  - model_name: claude-sonnet-4.6-or
    litellm_params:
      model: openrouter/anthropic/claude-sonnet-4.6
      api_key: os.environ/OPENROUTER_API_KEY

  - model_name: gpt-4o
    litellm_params:
      model: openrouter/openai/gpt-4o
      api_key: os.environ/OPENROUTER_API_KEY

  # ── Tier 5: Reasoning / Deep Analysis ─────────────────────────
  - model_name: claude-opus-4.6
    litellm_params:
      model: openrouter/anthropic/claude-opus-4.6
      api_key: os.environ/OPENROUTER_API_KEY

  - model_name: gpt-5.2
    litellm_params:
      model: openrouter/openai/gpt-5.2
      api_key: os.environ/OPENROUTER_API_KEY

# ── Router Configuration ──────────────────────────────────────────
router_settings:
  routing_strategy: 'cost-based-routing' # cheapest first
  enable_pre_call_checks: true # check rate limits before calling
  retry_after: 15 # seconds between retries
  num_retries: 2
  timeout: 120 # match our existing 120s timeout
  allowed_fails: 3 # before marking provider unhealthy
  cooldown_time: 60 # seconds before retrying failed provider

  # Complexity-based routing (LiteLLM built-in)
  # Maps SIMPLE → cheap models, REASONING → premium models
  enable_tag_filtering: true

# ── Budget & Spend Controls ───────────────────────────────────────
general_settings:
  master_key: os.environ/LITELLM_MASTER_KEY
  database_url: os.environ/LITELLM_DATABASE_URL # Postgres for LiteLLM internals

  # Budget enforcement
  max_budget: 50.0 # $50/day hard cap (LLM_DAILY_BUDGET equivalent)
  budget_duration: '1d'

  # Presidio PII masking
  guardrails:
    - guardrail_name: 'pii-masking'
      litellm_params:
        guardrail: presidio
        mode: during_call # mask on input, unmask on output
        output_parse_pii: true # restore PII in responses
        presidio_ad_hoc_recognizers:
          - entity_type: AU_PHONE
            regex: "\\+61\\d{9}"
            score: 0.9

# ── Logging ───────────────────────────────────────────────────────
litellm_settings:
  success_callback: ['custom_callback_handler'] # our custom handler → SQLite
  failure_callback: ['custom_callback_handler']
  service_callback: ['custom_callback_handler']
  set_verbose: false
  drop_params: true # drop unsupported params instead of erroring
```

### §20.4 Workload Classification Taxonomy

Rather than hardcoding model names, callers declare a **workload type**. This maps to LiteLLM's
tag-based routing, where tags select model groups optimised for that workload.

**Taxonomy** (adapted from OpenRouter's Google TagClassifier + LiteLLM's complexity router):

| Workload Type    | LiteLLM Tag          | Default Model Group                       | Current Stage(s)                 | Accuracy Need |
| ---------------- | -------------------- | ----------------------------------------- | -------------------------------- | ------------- |
| `classification` | `workload:classify`  | gpt-4o-mini, gemini-flash                 | scoring, rescoring, replies      | Medium–High   |
| `extraction`     | `workload:extract`   | gpt-4o-mini, gemini-flash                 | enrichment, contact-repair       | High          |
| `generation`     | `workload:generate`  | claude-haiku-4.5, gpt-4o-mini             | proposals, outreach              | Medium        |
| `analysis`       | `workload:analyze`   | claude-sonnet-4.6, gpt-4o                 | scoring (vision), overseer       | High          |
| `translation`    | `workload:translate` | gpt-4o-mini, gemini-flash                 | proposals (multilingual)         | Medium        |
| `summarisation`  | `workload:summarize` | gpt-4o-mini, llama-4-unlimited            | keywords, error-classification   | Low–Medium    |
| `vision`         | `workload:vision`    | gpt-4o-mini, gemini-flash                 | scoring, rescoring (screenshots) | High          |
| `reasoning`      | `workload:reason`    | claude-sonnet-4.6, gpt-5.2                | agents (developer, architect)    | Very High     |
| `simple-qa`      | `workload:simple`    | llama-4-unlimited, gemini-flash-unlimited | name-extractor, keyword-filter   | Low           |

**How callers use it:**

```javascript
// Before (hardcoded model):
const result = await callLLM({ model: 'openai/gpt-4o-mini', messages, stage: 'scoring' });

// After (workload-based):
const result = await callLLM({
  messages,
  stage: 'scoring',
  workloadType: 'classification', // maps to LiteLLM tag → routes to cheapest qualifying model
  accuracyTolerance: 0.9, // used by feedback sidecar for routing adjustments
});
```

Callers MAY still pass `model` as a direct override (sets `X-Model-Override` header), but the
default path lets LiteLLM pick the cheapest model from the workload's model group.

### §20.5 Provider Priority & Subscription Draining

LiteLLM's cost-based routing naturally picks the cheapest provider. We control priority by
configuring model groups with explicit ordering:

**Priority logic (implemented via LiteLLM model groups + custom routing):**

1. **Abacus.ai unlimited tier** ($10/mo, unlimited for cheap models) → drain first for `simple-qa`, `summarisation`
2. **Groq / DeepInfra** → fast + cheap for open-source models
3. **OpenRouter** → widest model selection, good fallback
4. **Anthropic Direct** → no markup for Claude models (when we enable ANTHROPIC_API_KEY)
5. **Azure / HuggingFace / Others** → additional fallbacks as needed

**Subscription draining** is handled by putting unlimited-tier models first in the model list
for applicable workloads. LiteLLM's cost-based router will pick them since their effective cost
is $0 (flat subscription). If Abacus.ai rate-limits or fails, LiteLLM auto-falls through to
the next model in the group.

**Note on Claude Max:** As of March 2026, Max subscription does NOT provide API credits — API
calls are billed separately. If Anthropic adds API credits to Max in the future, add a
`claude-max/` model entry to the config and LiteLLM will drain it first.

**Note on Abacus.ai:** The $10/month Unlimited plan includes unlimited calls to GPT-5 Mini,
Gemini Flash, and Llama 4 (chat models). Premium models (Claude, GPT-5) use a credit system.
Configure Abacus.ai models at the top of the model list for `simple-qa` and `summarisation`
workloads.

### §20.6 Success Feedback Loop (Custom Extension)

LiteLLM doesn't have a feedback mechanism. We build this as a thin Node.js sidecar service
that stores feedback in our existing SQLite database and periodically adjusts LiteLLM's routing
configuration.

**Feedback API (sidecar, port 4001):**

```
POST http://localhost:4001/v1/feedback
{
  "request_id": "chatcmpl-abc123",     // from LiteLLM response id
  "success_percentage": 85,             // 0-100, caller's assessment
  "failure_reasons": ["missing_field"], // optional tags
  "workload_type": "extraction"         // echoed for correlation
}
```

**How callers calculate success_percentage:**

| Stage            | Success calculation                                                    |
| ---------------- | ---------------------------------------------------------------------- |
| Scoring          | 100% if valid JSON with all required fields; −20% per missing field    |
| Enrichment       | (contacts found / expected range midpoint) × 100, capped at 100%       |
| Proposals        | 100% minus 5% per QA cross-check failure (length, tone, compliance)    |
| Reply classifier | 100% if intent matches human label; 0% if mismatch                     |
| Outreach (forms) | 100% if form submitted successfully; 0% if field mapping failed        |
| Error classifier | 100% if classification matches manual review; 50% if partial           |
| Overseer         | 100% if recommended action was valid; 0% if action failed or was wrong |
| Name extractor   | (names extracted / names in source) × 100                              |

**Accuracy aggregation (SQLite):**

```sql
CREATE TABLE llm_feedback (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id TEXT NOT NULL,
  model TEXT NOT NULL,            -- canonical model name (from LiteLLM response)
  provider TEXT NOT NULL,         -- provider used (from LiteLLM response headers)
  workload_type TEXT NOT NULL,
  success_percentage REAL NOT NULL,
  failure_reasons TEXT,           -- JSON array
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX idx_llm_feedback_model_workload ON llm_feedback(model, workload_type);

-- 7-day rolling average accuracy per model × workload
SELECT model, workload_type,
       AVG(success_percentage) as avg_accuracy,
       COUNT(*) as sample_size
FROM llm_feedback
WHERE created_at >= datetime('now', '-7 days')
GROUP BY model, workload_type;
```

**Routing adjustment:** The sidecar runs a daily cron job that:

1. Queries accuracy data per model × workload
2. If a model consistently scores below accuracy tolerance (20+ samples, 7-day avg < floor):
   - Deprioritise it in LiteLLM config (move down in model group, or remove)
   - Log alert for human review
3. If a cheaper model matches accuracy of a more expensive one:
   - Suggest swap (or auto-promote if `auto_adjust: true`)
4. Updates LiteLLM config via its `/model/update` admin API

**Cold start:** New models start with `accuracy = null` (treated as "unknown, allow"). After 20+
samples, the accuracy score is used for routing decisions.

### §20.7 A/B Testing Engine (Custom Extension)

The sidecar can split traffic for a workload type across multiple models to discover which gives
the best bang-for-buck.

**Configuration (SQLite):**

```sql
CREATE TABLE llm_ab_tests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workload_type TEXT NOT NULL,
  variants TEXT NOT NULL,          -- JSON: [{model, weight}]
  accuracy_tolerance REAL DEFAULT 0.8,
  min_samples INTEGER DEFAULT 50,  -- per variant before drawing conclusions
  auto_promote INTEGER DEFAULT 1,  -- auto-switch to winner when test completes
  status TEXT DEFAULT 'running',   -- running | completed | cancelled
  results TEXT,                    -- JSON: populated when completed
  started_at TEXT DEFAULT (datetime('now')),
  completed_at TEXT
);
```

**How it works:**

1. When a request arrives with a workload type that has an active A/B test, the sidecar
   intercepts the model selection and assigns a variant based on weights
2. The sidecar tells the application which model to request from LiteLLM (via response header)
3. Feedback flows back through `/v1/feedback` as normal
4. After `min_samples` per variant, the sidecar calculates bang-for-buck:

```
A/B Test Results: classification (50 samples each)
┌─────────────────┬──────────┬────────────┬───────────┬──────────────┐
│ Model           │ Accuracy │ Avg Cost   │ Avg Latency│ Bang-for-Buck│
├─────────────────┼──────────┼────────────┼───────────┼──────────────┤
│ gpt-4o-mini     │ 92%      │ $0.0031    │ 1.2s      │ 296.8        │
│ gemini-2.5-flash│ 89%      │ $0.0018    │ 0.8s      │ 494.4        │
│ llama-4-scout   │ 84%      │ $0.0005    │ 0.3s      │ 1680.0       │
└─────────────────┴──────────┴────────────┴───────────┴──────────────┘
Winner: llama-4-scout (best cost-efficiency meeting 85% threshold)
```

`bang_for_buck = accuracy / cost` — higher is better. If `auto_promote` is set, the winner
becomes the default for that workload in LiteLLM's config.

#### Empirical Meta-Learnings from Manual A/B Tests

Before the proxy exists, we've already run manual benchmarks (e.g. proposal polish across 7 models,
50 samples each). Key insights that should shape the proxy's A/B testing design:

1. **Model IDs are fragile.** OpenRouter model IDs change without notice (`claude-haiku-3` vs
   `claude-3-haiku`, `gemini-2.5-flash-preview` vs `gemini-2.5-flash-preview:free`). The proxy
   must validate model IDs on test startup and skip/alert on invalid IDs rather than burning
   budget on 50 guaranteed-to-fail calls.

2. **"Valid JSON" is the first-pass filter, not accuracy.** For structured output tasks (JSON
   response format), some models return 100% valid JSON and others 96%. The 4% failure rate
   compounds across thousands of calls. A/B tests should track `valid_response_rate` as a
   separate metric from `accuracy` — a model that's 5% cheaper but fails to produce valid
   output 4% of the time may cost more overall due to retries.

3. **Latency variance matters more than average latency.** DeepSeek V3 averaged 7s vs Gemini
   Flash's 1.7s — a 4x difference. For sequential workloads (N polish calls per site), latency
   multiplies by N. The proxy should measure p95 latency, not just mean, and weight it in the
   bang-for-buck calculation for throughput-sensitive workloads.

4. **Language handling is an invisible quality dimension.** Models that score identically on
   English may diverge on multilingual: one correctly translates to the target language while
   another ignores the language instruction entirely. A/B tests need language-stratified accuracy
   metrics — a model that's 10x cheaper but breaks 15 non-English markets is a bad trade.

5. **"Polish" tasks are the best A/B test candidates.** Tasks with clear input/output contracts
   (grammar fix, JSON in → JSON out, no new information) are ideal because quality is
   objectively measurable. Start A/B testing with these before tackling subjective tasks
   like proposal generation.

6. **Cost savings are multiplicative with call volume.** Polish runs N times per site (once per
   contact). An 8x cost reduction on the polish model saves more than an 8x reduction on the
   generation model (which runs once per site). The proxy's A/B test priority queue should
   factor in call frequency per workload type, not just per-call cost.

7. **Faithfulness vs editorial quality are different axes.** Some models (Gemini) are highly
   faithful (minimal changes, preserves original structure). Others (Haiku) are more editorial
   (rewrites quotes, restructures sentences). Neither is universally better — the proxy should
   let workload definitions specify whether faithfulness or editorial quality is preferred, and
   score accordingly.

### §20.8 Budget Enforcement

LiteLLM handles budget enforcement natively. We configure it via the YAML config and virtual keys:

**What LiteLLM handles:**

- **Daily hard cap** (`max_budget: 50.0`, `budget_duration: "1d"`) → auto HTTP 429
- **Per-key budgets** → virtual keys can have individual spend limits
- **Per-team budgets** → group keys by team with shared budgets
- **Rate limiting** → requests per minute/day per key

**What the sidecar adds:**

- **Per-workload-type variance monitoring** → compare actual avg cost vs expected (from our
  `llm_cost_budgets` table). Alert if actual > 3× expected.
- **Model mismatch alerting** → if LiteLLM routes to a model that doesn't match the expected
  model for a workload type, log a warning
- **Hourly spend alerts** → query LiteLLM's spend API, warn if hourly spend exceeds threshold

**Budget table (in our SQLite, queried by sidecar):**

```sql
-- Already exists from migration 079, extended with workload_type
CREATE TABLE llm_cost_budgets (
  call_type TEXT PRIMARY KEY,             -- matches workload_type or stage
  expected_cost_per_call REAL NOT NULL,
  max_cost_per_call REAL NOT NULL,        -- 3x expected, triggers alert
  expected_model TEXT NOT NULL,
  updated_at TEXT DEFAULT (datetime('now'))
);
```

### §20.9 Application Code Migration

**`src/utils/llm-provider.js` becomes a thin HTTP client:**

```javascript
// After migration — llm-provider.js is ~50 lines
import axios from 'axios';

const LITELLM_URL = process.env.LLM_PROXY_URL || 'http://localhost:4000';
const LITELLM_KEY = process.env.LLM_PROXY_KEY || ''; // virtual key from LiteLLM
const SIDECAR_URL = process.env.LLM_SIDECAR_URL || 'http://localhost:4001';

// Workload type → LiteLLM tag mapping
const WORKLOAD_TAGS = {
  classification: 'workload:classify',
  extraction: 'workload:extract',
  generation: 'workload:generate',
  analysis: 'workload:analyze',
  translation: 'workload:translate',
  summarisation: 'workload:summarize',
  vision: 'workload:vision',
  reasoning: 'workload:reason',
  'simple-qa': 'workload:simple',
};

export async function callLLM({
  model = null,
  messages,
  temperature = 0.7,
  max_tokens = 2000,
  json_mode = false,
  stage = null,
  siteId = null,
  workloadType = null,
  accuracyTolerance = 0.8,
  modelOverride = false,
}) {
  const body = { messages, temperature, max_tokens };
  if (model && modelOverride) body.model = model;
  if (json_mode) body.response_format = { type: 'json_object' };

  // LiteLLM metadata for tracking + tag-based routing
  body.metadata = {};
  if (stage) body.metadata.stage = stage;
  if (siteId) body.metadata.site_id = siteId;
  if (workloadType) {
    body.metadata.tags = [WORKLOAD_TAGS[workloadType] || workloadType];
  }

  const response = await axios.post(`${LITELLM_URL}/v1/chat/completions`, body, {
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${LITELLM_KEY}`,
    },
    timeout: 120000,
  });

  const choice = response.data.choices[0];
  const usage = response.data.usage || {};

  return {
    content: choice?.message?.content || '',
    usage: {
      promptTokens: usage.prompt_tokens || 0,
      completionTokens: usage.completion_tokens || 0,
    },
    meta: {
      requestId: response.data.id,
      modelUsed: response.data.model,
      cost: usage.cost || 0,
    },
  };
}

// Report feedback to sidecar
export async function reportFeedback(requestId, successPercentage, failureReasons = []) {
  try {
    await axios.post(
      `${SIDECAR_URL}/v1/feedback`,
      {
        request_id: requestId,
        success_percentage: successPercentage,
        failure_reasons: failureReasons,
      },
      { timeout: 5000 }
    );
  } catch (err) {
    // Never let feedback failures break the caller
    console.warn(`Feedback reporting failed: ${err.message}`);
  }
}
```

**What gets removed from application code:**

- `MODEL_PRICING` table → LiteLLM owns all pricing
- `logLLMUsage()` calls → LiteLLM logs everything automatically
- `getDailySpend()` / `getHourlySpend()` → LiteLLM enforces budgets
- `checkBudgetVariance()` → sidecar does variance monitoring
- `ANTHROPIC_API_KEY` / `OPENROUTER_API_KEY` from `.env` → LiteLLM config holds keys
- Direct Anthropic SDK usage → LiteLLM handles provider translation
- Direct axios calls to `openrouter.ai` → LiteLLM handles routing
- `callAnthropicAPI()` / `callOpenRouterAPI()` functions → LiteLLM handles both

**What stays in application code:**

- `callLLM()` function (now thin HTTP client to LiteLLM)
- `reportFeedback()` function (HTTP client to sidecar)
- `stage` and `workloadType` parameters at each callsite
- Success percentage calculation logic (unique to each module)

### §20.10 Custom Callback Handler (LiteLLM → SQLite Bridge)

LiteLLM natively logs to Postgres, but we want data in our SQLite `llm_usage` table for
dashboard compatibility. A custom callback handler bridges the gap:

```python
# config/litellm_callbacks.py
import litellm
import sqlite3
import os

DB_PATH = os.environ.get('SQLITE_DB_PATH', '/home/jason/code/333Method/db/sites.db')

class SQLiteCallback(litellm.integrations.custom_logger.CustomLogger):
    def log_success_event(self, kwargs, response_obj, start_time, end_time):
        metadata = kwargs.get('metadata', {})
        usage = response_obj.get('usage', {})

        conn = sqlite3.connect(DB_PATH)
        try:
            conn.execute('''
                INSERT INTO llm_usage (
                    site_id, stage, provider, model,
                    prompt_tokens, completion_tokens, total_tokens,
                    estimated_cost, request_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ''', (
                metadata.get('site_id'),
                metadata.get('stage'),
                kwargs.get('litellm_params', {}).get('custom_llm_provider', 'unknown'),
                kwargs.get('model', 'unknown'),
                usage.get('prompt_tokens', 0),
                usage.get('completion_tokens', 0),
                usage.get('total_tokens', 0),
                usage.get('cost', 0),
                response_obj.get('id'),
            ))
            conn.commit()
        finally:
            conn.close()

    def log_failure_event(self, kwargs, response_obj, start_time, end_time):
        # Log failures for monitoring
        pass
```

This ensures our existing dashboard (`11_LLM_Costs.py`) and all SQLite-based analytics continue
working without modification.

### §20.11 Secret Detection (Custom Extension)

LiteLLM's Presidio integration handles PII (names, phone numbers, emails) but NOT secrets
(API keys, JWTs, high-entropy tokens). We add secret detection as a pre-processing step in the
sidecar, or as an additional guardrail callback in LiteLLM.

**Detection layers (our `src/utils/secret-patterns.js`):**

```javascript
export const SECRET_PATTERNS = [
  { name: 'anthropic_key', regex: /sk-ant-[A-Za-z0-9\-_]{40,}/g },
  { name: 'openrouter_key', regex: /sk-or-v\d+-[A-Za-z0-9]{40,}/g },
  { name: 'openai_key', regex: /sk-[A-Za-z0-9]{48}/g },
  { name: 'resend_key', regex: /re_[A-Za-z0-9]{36}/g },
  { name: 'jwt', regex: /eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/g },
  { name: 'hex_secret', regex: /\b[0-9a-f]{32,}\b/gi },
  { name: 'base64_block', regex: /[A-Za-z0-9+/]{40,}={0,2}/g },
  { name: 'bearer_token', regex: /Bearer\s+[A-Za-z0-9\-._~+/]+=*/g },
];

export const SECRET_KEY_NAMES = [
  'password',
  'passwd',
  'secret',
  'token',
  'api_key',
  'apikey',
  'authorization',
  'credential',
  'private_key',
  'access_token',
];
```

**High-entropy detection:** Shannon entropy > 4.5 bits/char AND length ≥ 20 → flagged as likely
secret. Catches random tokens, UUIDs, base64 that don't match known patterns.

**Integration options:**

1. **LiteLLM guardrail callback** (preferred) — register as a custom guardrail that runs before
   PII scrubbing. Detected secrets are replaced with `[SECRET:type:N]` placeholders. Not restored
   on response (truncated form only: first 9 + last 8 chars).
2. **Sidecar pre-processing** — application calls sidecar first to scrub secrets, then calls
   LiteLLM with clean payload. More latency but simpler to implement.

### §20.12 Git Pre-Commit Secret Detection

Same `src/utils/secret-patterns.js` module powers a pre-commit hook:

```javascript
// scripts/detect-secrets.js — called from pre-commit hook
import { detectSecrets } from '../src/utils/secret-patterns.js';
import { execSync } from 'node:child_process';

const diff = execSync('git diff --cached').toString();
const found = detectSecrets(diff);

if (found.length > 0) {
  console.error('\n❌ Potential secrets detected in staged changes:\n');
  found.forEach(({ file, line, category, preview }) =>
    console.error(`  ${file}:${line}  [${category}]  ${preview}`)
  );
  console.error('\nRun: git reset HEAD <file> to unstage\n');
  process.exit(1);
}
```

Hard block — commit fails if a secret is detected. The git hook focuses on source files and
config; LiteLLM + sidecar focuses on runtime LLM payloads. Both use the same pattern library.

### §20.13 Deployment

**Docker Compose (recommended for local development):**

```yaml
# docker-compose.llm-proxy.yaml
services:
  litellm:
    image: ghcr.io/berriai/litellm:main-latest
    ports:
      - '4000:4000'
    volumes:
      - ./config/litellm-config.yaml:/app/config.yaml:ro
      - ./config/litellm_callbacks.py:/app/litellm_callbacks.py:ro
    environment:
      - LITELLM_MASTER_KEY=${LITELLM_MASTER_KEY}
      - LITELLM_DATABASE_URL=postgresql://litellm:${LITELLM_DB_PASS}@litellm-db:5432/litellm
      - OPENROUTER_API_KEY=${OPENROUTER_API_KEY}
      - ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}
      - GROQ_API_KEY=${GROQ_API_KEY}
      - DEEPINFRA_API_KEY=${DEEPINFRA_API_KEY}
      - ABACUS_API_KEY=${ABACUS_API_KEY}
      - SQLITE_DB_PATH=/data/sites.db
    command: ['--config', '/app/config.yaml']
    depends_on:
      - litellm-db

  litellm-db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: litellm
      POSTGRES_PASSWORD: ${LITELLM_DB_PASS}
      POSTGRES_DB: litellm
    volumes:
      - litellm-pgdata:/var/lib/postgresql/data

  llm-sidecar:
    build: ./src/proxy/sidecar
    ports:
      - '4001:4001'
    environment:
      - DATABASE_PATH=/data/sites.db
      - LITELLM_URL=http://litellm:4000
      - LITELLM_MASTER_KEY=${LITELLM_MASTER_KEY}
    volumes:
      - ./db:/data:rw

volumes:
  litellm-pgdata:
```

**NixOS systemd service (production):**

```nix
# /etc/nixos/services.nix (add)
systemd.user.services."333method-llm-proxy" = {
  description = "333 Method LLM Proxy (LiteLLM + Sidecar)";
  after = [ "network.target" "docker.service" ];
  wantedBy = [ "default.target" ];
  serviceConfig = {
    ExecStart = "${pkgs.docker-compose}/bin/docker-compose -f /home/jason/code/333Method/docker-compose.llm-proxy.yaml up";
    ExecStop = "${pkgs.docker-compose}/bin/docker-compose -f /home/jason/code/333Method/docker-compose.llm-proxy.yaml down";
    Restart = "always";
    RestartSec = 10;
  };
};
```

**Distributed (later phases):**

Workers connect to LiteLLM on the VPS over NetBird VPN:

```
Worker PC → NetBird VPN → VPS:4000 (LiteLLM) → Provider APIs
  └─ No API keys locally
  └─ Auth via LiteLLM virtual key (scoped per worker)
  └─ PII scrubbed by Presidio before forwarding to provider
```

### §20.14 Database Migrations

**`db/migrations/080-llm-proxy-feedback.sql`:**

```sql
-- Feedback from callers for routing optimisation
CREATE TABLE IF NOT EXISTS llm_feedback (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id TEXT NOT NULL,
  model TEXT NOT NULL,
  provider TEXT NOT NULL,
  workload_type TEXT NOT NULL,
  success_percentage REAL NOT NULL,
  failure_reasons TEXT,           -- JSON array
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX idx_llm_feedback_model_workload ON llm_feedback(model, workload_type);
CREATE INDEX idx_llm_feedback_time ON llm_feedback(created_at DESC);

-- A/B test definitions
CREATE TABLE IF NOT EXISTS llm_ab_tests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workload_type TEXT NOT NULL,
  variants TEXT NOT NULL,          -- JSON: [{model, weight}]
  accuracy_tolerance REAL DEFAULT 0.8,
  min_samples INTEGER DEFAULT 50,
  auto_promote INTEGER DEFAULT 1,
  status TEXT DEFAULT 'running',   -- running, completed, cancelled
  results TEXT,                    -- JSON: populated when completed
  started_at TEXT DEFAULT (datetime('now')),
  completed_at TEXT
);

-- Routing override log (tracks what the sidecar changed and why)
CREATE TABLE IF NOT EXISTS llm_routing_changes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workload_type TEXT NOT NULL,
  old_model TEXT,
  new_model TEXT NOT NULL,
  reason TEXT NOT NULL,            -- 'ab-test:123', 'accuracy-floor', 'manual'
  avg_accuracy REAL,
  sample_size INTEGER,
  created_at TEXT DEFAULT (datetime('now'))
);
```

### §20.15 Environment Variables

```bash
# Application-side (.env)
LLM_PROXY_URL=http://localhost:4000       # LiteLLM endpoint
LLM_PROXY_KEY=sk-litellm-...             # LiteLLM virtual key
LLM_SIDECAR_URL=http://localhost:4001     # Feedback/A/B sidecar

# Proxy-side (.env.secrets — only accessible to Docker containers)
LITELLM_MASTER_KEY=sk-master-...          # LiteLLM admin key
LITELLM_DB_PASS=<random-password>         # Postgres password
OPENROUTER_API_KEY=sk-or-...
ANTHROPIC_API_KEY=sk-ant-...
GROQ_API_KEY=gsk_...
DEEPINFRA_API_KEY=...
ABACUS_API_KEY=...
# Add more provider keys as needed
```

### §20.16 Dashboard Integration

The existing `dashboard/pages/11_LLM_Costs.py` continues working unchanged — the custom
callback handler (§20.10) writes to the same `llm_usage` table it already queries.

**New sections powered by sidecar data:**

- **Feedback accuracy** — Rolling accuracy by model × workload (from `llm_feedback` table)
- **A/B test results** — Live comparison of model performance per workload
- **Routing changes** — Log of automatic and manual routing adjustments
- **Provider distribution** — Pie chart of traffic by provider (from `llm_usage.provider`)

### §20.17 Integration Tests

```javascript
// tests/llm-proxy.integration.test.js

// LiteLLM core (verify our config works)
test('LiteLLM health check returns 200');
test('Chat completion with virtual key succeeds');
test('Chat completion without key returns 401');
test('Budget exceeded returns 429');
test('Unknown model falls back to default');

// Tag-based routing
test('workload:simple tag routes to cheap model');
test('workload:reason tag routes to premium model');
test('workload:vision tag routes to vision-capable model');

// Custom callback → SQLite
test('Successful request logged to llm_usage table');
test('Failed request logged with error details');
test('Metadata (stage, site_id) preserved in llm_usage');

// Sidecar: Feedback
test('POST /v1/feedback stores success percentage in llm_feedback');
test('Low-accuracy model flagged after 20+ samples');

// Sidecar: A/B testing
test('Active A/B test splits traffic by variant weights');
test('Completed A/B test promotes winner to routing config');

// Sidecar: Secret detection
test('API key in prompt detected and replaced');
test('High-entropy string detected and replaced');
test('Normal text not falsely flagged');

// Presidio PII (LiteLLM built-in)
test('Person name masked in request and restored in response');
test('Phone number masked with same format');
test('PII masking disabled via header when needed');

// Git hook
test('Pre-commit blocks staged API key');
test('Pre-commit allows clean diff');
```

### §20.18 Implementation Phases

**Phase 0a: Deploy LiteLLM + migrate callLLM() (2–3h)**

- Create `config/litellm-config.yaml` with current providers (OpenRouter, Anthropic)
- Deploy LiteLLM via Docker Compose
- Create virtual key for pipeline
- Rewrite `callLLM()` as thin HTTP client
- Write custom callback handler (LiteLLM → SQLite bridge)
- Remove API keys from `.env`, move to `.env.secrets`
- Verify all pipeline stages work through LiteLLM

**Phase 0b: Add providers + workload routing (1–2h)**

- Add Groq, DeepInfra, Abacus.ai to LiteLLM config
- Configure tag-based routing for workload taxonomy
- Add `workloadType` parameter to all callsites
- Configure subscription draining priority (Abacus.ai first)

**Phase 0c: Feedback sidecar + A/B testing (2–3h)**

- Build Node.js sidecar with `/v1/feedback` endpoint
- Migration 080 (feedback + A/B test tables)
- `reportFeedback()` calls added to key stages
- A/B test engine (split traffic, measure, promote)
- First A/B test: `classification` workload (gpt-4o-mini vs gemini-flash vs llama-4)

**Phase 0d: Secret detection + PII tuning (1–2h)**

- Register `secret-patterns.js` as LiteLLM custom guardrail
- Configure Presidio for AU phone numbers, NZ/UK formats
- Pre-commit hook (`scripts/detect-secrets.js`)
- Integration tests

**Total estimated effort:** 6–10 hours Claude Code, 20–30 hours human

**Savings vs building from scratch:** ~50% less effort. LiteLLM handles provider adapters
(100+ providers), model mapping, budget enforcement, rate limiting, Presidio PII, health checks,
admin API, and Postgres-backed state — all battle-tested with 20k+ GitHub stars. We focus only on
the differentiated logic: feedback loops, A/B testing, secret detection, and pipeline-specific
workload taxonomy.

---

</details>

## Part 21: Distributed Telemetry, Instrumentation & Remote Auto-Repair

**Added:** 2026-03-05

The distributed system spans a VPS plus N borrowed worker PCs. This part defines the observability
stack that lets a central controller detect failures and fix them automatically — without requiring
SSH access to individual worker machines.

### Design Principles

1. **Pull-based metrics** — workers expose `/metrics`; central Prometheus scrapes via NetBird VPN
2. **Push-based logs** — workers push structured NDJSON logs to a central Vector collector
3. **Correlation IDs** — every agent task, LLM call, and outreach shares a trace ID via
   `AsyncLocalStorage` (already partially implemented in `src/utils/logger.js`)
4. **Self-healing via agent tasks** — Monitor agent already reads logs and creates fix tasks.
   Telemetry extends this to feed machine-level health events into the same task queue.
5. **Low overhead on borrowed PCs** — all instrumentation targets <3% CPU / <50 MB RAM. Workers
   can opt out of OTEL tracing sampling without breaking core functionality.
6. **Learnings applied from existing system:**
   - No `detached: true` / `child.unref()` in any spawned process (zombie root cause)
   - `PRAGMA busy_timeout = 5000` on every new SQLite-touching module
   - Budget guards: telemetry monitor respects the `$10/day` agent budget cap
   - Circuit breaker for every external dependency (Prometheus, Loki, Vector)

### Architecture Overview

```
Borrowed PC Worker N                   Central VPS (333method-internal)
┌──────────────────────┐              ┌───────────────────────────────────────┐
│  Agent Process        │              │  Prometheus (scrape pull)             │
│  ├─ prom-client       │──metrics──▶ │  ├─ scrapes :9100/metrics every 15s  │
│  │  :9100/metrics     │              │  └─ stores time-series (TSDB)         │
│  ├─ pino logger       │──logs────▶  │                                       │
│  │  NDJSON → socket   │              │  Vector (log aggregation)             │
│  └─ OTEL SDK (opt-in) │──traces───▶ │  ├─ receives pino NDJSON via TCP      │
│     1% sampling        │              │  ├─ enriches: machine_id, region     │
└──────────────────────┘              │  └─ forwards to Loki                  │
                                       │                                       │
                                       │  Loki (log storage + query)           │
                                       │  Grafana (visualisation)              │
                                       │  ├─ Fleet dashboard (infra)           │
                                       │  └─ Log Explorer (correlation IDs)    │
                                       │                                       │
                                       │  Auto-Repair Loop                     │
                                       │  src/cron/telemetry-monitor.js        │
                                       │  ├─ polls Prometheus alert API        │
                                       │  └─ creates agent_tasks for fixes     │
                                       └───────────────────────────────────────┘
```

### Dashboard Separation (Confirmed)

Dashboard v2 (React + FastAPI) and Grafana serve distinct purposes and remain **separate**:

| Dashboard v2 (React + FastAPI)               | Grafana (Prometheus + Loki)                   |
| -------------------------------------------- | --------------------------------------------- |
| Pipeline business metrics (funnel, outreach) | Machine infrastructure (CPU, memory, load)    |
| Agent task queue and approvals               | Worker fleet health and alerting              |
| Cost forecast and profitability              | LLM token consumption time-series             |
| Human review queue                           | Distributed log search with correlation IDs   |
| Reads precomputed `dashboard_cache` table    | Reads Prometheus TSDB + Loki directly         |
| `dashboard.molecool.org` (Caddy + NetBird)   | `grafana.molecool.org` (same VPN restriction) |

The **Operations tab** in Dashboard v2 covers central VPS health (cron status, browser loop).
Grafana Fleet covers borrowed worker PCs. No page consolidation needed.

### Worker Node Instrumentation

#### Metrics (`prom-client`, `src/utils/worker-metrics.js`)

```javascript
import { collectDefaultMetrics, Counter, Histogram, Gauge, register } from 'prom-client';
import http from 'node:http';
import os from 'node:os';

collectDefaultMetrics({ prefix: 'agent_' }); // CPU, memory, GC, event loop lag

export const tasksCompleted = new Counter({
  name: 'agent_tasks_completed_total',
  labelNames: ['agent_name', 'outcome'], // outcome: success|failure|retry
});
export const taskDuration = new Histogram({
  name: 'agent_task_duration_seconds',
  labelNames: ['agent_name', 'task_type'],
  buckets: [5, 15, 30, 60, 120, 300, 600],
});
export const llmTokensUsed = new Counter({
  name: 'agent_llm_tokens_total',
  labelNames: ['agent_name', 'model', 'type'], // type: input|output
});
export const piiScrubCount = new Counter({
  name: 'agent_pii_scrubbed_total',
  labelNames: ['tier'], // tier: pii|secret
});
export const proxyBypassed = new Counter({
  name: 'agent_proxy_bypassed_total',
  help: 'LLM calls that bypassed privacy proxy (circuit open)',
  labelNames: ['agent_name'],
});
export const machineLoad = new Gauge({
  name: 'agent_machine_load_1min',
  collect() {
    this.set(os.loadavg()[0]);
  },
});

// Expose on port 9100 — reachable only via NetBird VPN
const server = http.createServer((req, res) => {
  if (req.url === '/metrics') {
    res.setHeader('Content-Type', register.contentType);
    register.metrics().then(m => res.end(m));
  } else {
    res.writeHead(404).end();
  }
});
server.listen(9100, '0.0.0.0');
// No detached/unref — server dies with the agent process (zombie prevention)
```

#### Structured Logging (`pino` + `AsyncLocalStorage`, upgrade to `src/utils/logger.js`)

The existing logger uses a custom format. This upgrade adds correlation ID propagation via
`AsyncLocalStorage` and pipes to Vector in production:

```javascript
import pino from 'pino';
import { AsyncLocalStorage } from 'node:async_hooks';

export const traceContext = new AsyncLocalStorage();

export const logger = pino({
  level: process.env.LOG_LEVEL || 'info',
  transport: process.env.VECTOR_LOG_SOCKET
    ? { target: 'pino/file', options: { destination: process.env.VECTOR_LOG_SOCKET } }
    : { target: 'pino-pretty' },
  mixin() {
    const ctx = traceContext.getStore() || {};
    return {
      machine_id: process.env.MACHINE_ID,
      agent_name: process.env.AGENT_NAME,
      task_id: ctx.taskId,
      trace_id: ctx.traceId,
      correlation_id: ctx.correlationId,
    };
  },
});

// Usage in agent handlers:
// traceContext.run({ taskId: task.id, traceId: uuidv4() }, () => { ... })
```

**Vector config on worker** (`/etc/vector/vector.toml`):

```toml
[sources.agent_logs]
  type    = "file"
  include = ["/tmp/agent-logs.sock"]

[transforms.enrich]
  type   = "remap"
  inputs = ["agent_logs"]
  source = '''
    .machine_id = get_env_var("MACHINE_ID") ?? "unknown"
    .region     = get_env_var("MACHINE_REGION") ?? "unknown"
  '''

[sinks.loki]
  type     = "loki"
  inputs   = ["enrich"]
  endpoint = "http://loki.333method.internal:3100"
  labels   = { machine_id = "{{ machine_id }}", agent = "{{ agent_name }}" }
```

#### OpenTelemetry Tracing (opt-in, 1% sampling)

```bash
# Set on specific machines during debug periods only
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector.333method.internal:4318
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.01
OTEL_SERVICE_NAME=agent-developer
OTEL_RESOURCE_ATTRIBUTES=machine_id=${MACHINE_ID}
```

Start with: `node --require @opentelemetry/auto-instrumentations-node/register src/agents/developer.js`

No code changes required — SDK auto-instruments `fetch`, `http`, `dns`, database drivers.

### Central Collection Services (Docker, `333method-internal`)

```nix
# modules/containers.nix additions
prometheus = {
  image  = "prom/prometheus:v3";
  volumes = [
    "/opt/333method/config/prometheus.yml:/etc/prometheus/prometheus.yml:ro"
    "prometheus_data:/prometheus"
  ];
  extraOptions = [ "--network=333method-internal" ];
};

grafana = {
  image   = "grafana/grafana:11";
  volumes = [ "grafana_data:/var/lib/grafana" ];
  environment.GF_SECURITY_ADMIN_PASSWORD_FILE = "/run/secrets/grafana_password";
  extraOptions = [ "--network=333method-internal" "--publish=3000:3000" ];
  # Port 3000 restricted to NetBird subnet via iptables (same pattern as dashboard 8501)
};

loki = {
  image   = "grafana/loki:3";
  volumes = [ "loki_data:/loki" "/opt/333method/config/loki.yml:/etc/loki/local-config.yaml:ro" ];
  extraOptions = [ "--network=333method-internal" ];
};

otel-collector = {
  image   = "otel/opentelemetry-collector-contrib:latest";
  volumes = [ "/opt/333method/config/otel-collector.yml:/etc/otel/config.yaml:ro" ];
  extraOptions = [ "--network=333method-internal" ];
};
```

**Prometheus scrape config** (`config/prometheus.yml`):

```yaml
global:
  scrape_interval: 15s

scrape_configs:
  - job_name: 'agent_workers'
    static_configs:
      - targets:
          - '100.64.0.1:9100' # machine-1 (NetBird assigned IP)
          - '100.64.0.2:9100' # machine-2
          # Add worker IPs as fleet grows
    relabel_configs:
      - source_labels: [__address__]
        target_label: machine_id
```

### Prometheus Alerting Rules (`config/prometheus-alerts.yml`)

```yaml
groups:
  - name: agent_system
    rules:
      - alert: AgentTaskHighFailureRate
        expr: |
          rate(agent_tasks_completed_total{outcome="failure"}[5m])
          / rate(agent_tasks_completed_total[5m]) > 0.30
        for: 2m
        labels: { severity: warning }

      - alert: AgentMemoryPressure
        expr: agent_process_resident_memory_bytes > 900e6
        for: 5m
        labels: { severity: warning }

      - alert: WorkerNodeUnreachable
        expr: up{job="agent_workers"} == 0
        for: 3m
        labels: { severity: critical }

      - alert: EventLoopLagHigh
        expr: agent_nodejs_eventloop_lag_seconds_p99 > 0.5
        for: 2m
        labels: { severity: warning }

      - alert: LLMTokenBudgetExceeded
        expr: increase(agent_llm_tokens_total[1h]) > 500000
        labels: { severity: warning }

      - alert: PrivacyProxyBypassed
        expr: increase(agent_proxy_bypassed_total[5m]) > 0
        labels: { severity: warning }
        annotations:
          summary: 'LLM calls bypassing privacy proxy on {{ $labels.machine_id }}'
```

### Auto-Repair Loop: `src/cron/telemetry-monitor.js`

Reads Prometheus alerts and translates them into `agent_tasks`. Runs every 2 minutes. Applies
existing agent system learnings: budget guard, deduplication (no duplicate tasks within 30 min),
`busy_timeout` on SQLite.

```javascript
export default async function run() {
  // Budget guard — respect $10/day agent budget
  const todaySpend = await getAgentSpendToday();
  if (todaySpend > DAILY_BUDGET_USD * 0.8) {
    logger.warn('Near daily budget limit — skipping telemetry-monitor task creation');
    return;
  }

  const alerts = await fetchPrometheusAlerts(); // GET /api/v1/alerts
  for (const alert of alerts.filter(a => a.state === 'firing')) {
    const template = ALERT_TO_TASK[alert.labels.alertname];
    if (!template) continue;

    // Deduplication: skip if same unfired task exists in last 30 min
    const exists = await db.get(
      `
      SELECT id FROM agent_tasks
      WHERE task_type = ? AND context_json LIKE ? AND status NOT IN ('completed','cancelled')
        AND created_at > datetime('now', '-30 minutes')
    `,
      [template.task_type, `%${alert.labels.machine_id}%`]
    );
    if (exists) continue;

    await createAgentTask({
      task_type: template.task_type,
      assigned_to: template.assigned_to,
      priority: template.priority,
      context: {
        alert_name: alert.labels.alertname,
        machine_id: alert.labels.machine_id,
        agent_name: alert.labels.agent_name,
        description: template.description,
        source: 'prometheus',
      },
    });

    // Log to telemetry_alerts table
    await db.run(
      `INSERT INTO telemetry_alerts
      (alert_name, machine_id, agent_name, severity, status, task_id_created, context_json)
      VALUES (?, ?, ?, ?, 'firing', ?, ?)`,
      [
        alert.labels.alertname,
        alert.labels.machine_id,
        alert.labels.agent_name,
        template.severity,
        task.id,
        JSON.stringify(alert),
      ]
    );
  }
}

const ALERT_TO_TASK = {
  AgentTaskHighFailureRate: {
    task_type: 'investigate_failures',
    assigned_to: 'monitor',
    priority: 7,
    severity: 'warning',
  },
  WorkerNodeUnreachable: {
    task_type: 'node_health_check',
    assigned_to: 'monitor',
    priority: 9,
    severity: 'critical',
  },
  AgentMemoryPressure: {
    task_type: 'restart_agent',
    assigned_to: 'monitor',
    priority: 6,
    severity: 'warning',
  },
  EventLoopLagHigh: {
    task_type: 'profile_agent',
    assigned_to: 'developer',
    priority: 5,
    severity: 'warning',
  },
  LLMTokenBudgetExceeded: {
    task_type: 'reduce_token_usage',
    assigned_to: 'architect',
    priority: 6,
    severity: 'warning',
  },
  PrivacyProxyBypassed: {
    task_type: 'investigate_proxy',
    assigned_to: 'monitor',
    priority: 8,
    severity: 'warning',
  },
};
```

### Remote Agent Management (`src/utils/worker-mgmt-server.js`)

A lightweight HTTP server on port 9101 (NetBird VPN only) accepts restart commands from the
Monitor agent. No SSH required:

```javascript
// Worker side — port 9101, bound to 0.0.0.0 (iptables restricts to NetBird subnet)
import http from 'node:http';
import { verify } from 'jsonwebtoken';

const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/restart') {
    try {
      const token = req.headers.authorization?.split(' ')[1];
      verify(token, process.env.MGMT_TOKEN_SECRET);
      const body = JSON.parse(/* read body */);
      restartAgent(body.agent_name);
      res.writeHead(200).end(JSON.stringify({ ok: true }));
    } catch (e) {
      res.writeHead(401).end(JSON.stringify({ error: e.message }));
    }
  } else {
    res.writeHead(404).end();
  }
});
server.listen(9101, '0.0.0.0');
// No unref() — server lifecycle tied to main process (zombie prevention)
```

**Central side** (Monitor agent `restart_agent` task handler):

```javascript
const peerIp = await getNetBirdPeerIP(machine_id);
const response = await fetch(`http://${peerIp}:9101/restart`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.MGMT_TOKEN}` },
  body: JSON.stringify({ agent_name }),
  signal: AbortSignal.timeout(10000),
});
```

### Grafana Dashboards

**Machine Fleet Dashboard** (Prometheus datasource):

| Panel                | Query                                      | Viz         |
| -------------------- | ------------------------------------------ | ----------- |
| Online Workers       | `count(up{job="agent_workers"} == 1)`      | Stat        |
| Tasks/min by Machine | `rate(agent_tasks_completed_total[1m])`    | Time series |
| Memory by Machine    | `agent_process_resident_memory_bytes`      | Time series |
| Event Loop Lag p99   | `agent_nodejs_eventloop_lag_seconds_p99`   | Gauge       |
| LLM Tokens/hr        | `increase(agent_llm_tokens_total[1h])`     | Bar chart   |
| PII Scrubs/hr        | `increase(agent_pii_scrubbed_total[1h])`   | Bar chart   |
| Proxy Bypass Events  | `increase(agent_proxy_bypassed_total[5m])` | Alert list  |
| Active Alerts        | Prometheus alerting API                    | Alert list  |

**Distributed Log Explorer** (Loki datasource):

- Machine selector → filters all log panels
- Error stream: `{job="agent_workers"} |= "ERROR"` with task_id and trace_id fields
- Slow task panel: `{job="agent_workers"} | json | task_duration_ms > 60000`
- Privacy bypass: `{job="agent_workers"} |= "proxy_bypassed"`

### New Database Migration: `db/migrations/078-telemetry-alert-log.sql`

```sql
CREATE TABLE IF NOT EXISTS telemetry_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  alert_time TEXT NOT NULL DEFAULT (datetime('now')),
  alert_name TEXT NOT NULL,
  machine_id TEXT,
  agent_name TEXT,
  severity TEXT NOT NULL,
  status TEXT NOT NULL CHECK(status IN ('firing','resolved')),
  task_id_created INTEGER,
  context_json TEXT
);
CREATE INDEX idx_telemetry_alerts_time ON telemetry_alerts(alert_time DESC);
CREATE INDEX idx_telemetry_alerts_machine ON telemetry_alerts(machine_id, alert_time DESC);
```

### New Environment Variables

```bash
# Worker telemetry
MACHINE_ID=worker-1               # unique per machine, set at provisioning
MACHINE_REGION=au-sydney
LOG_LEVEL=info
VECTOR_LOG_SOCKET=/tmp/agent-logs.sock
MGMT_TOKEN_SECRET=                # JWT secret for worker management API (Docker Secret)

# Central VPS
PROMETHEUS_URL=http://prometheus:9090
LOKI_URL=http://loki:3100
GRAFANA_URL=http://grafana:3000
OTEL_COLLECTOR_URL=http://otel-collector:4318
```

### Implementation Phases (fits into existing plan)

| Phase       | Work                                                                       | Effort |
| ----------- | -------------------------------------------------------------------------- | ------ |
| **Phase 5** | `worker-metrics.js`, Prometheus + Grafana + Loki containers, scrape config | 8h CC  |
| **Phase 5** | `logger.js` pino upgrade + `AsyncLocalStorage`, Vector on workers          | 6h CC  |
| **Phase 5** | `worker-mgmt-server.js`, NetBird peer API integration for remote restart   | 4h CC  |
| **Phase 5** | `llm-privacy-proxy` container, `src/utils/secret-patterns.js`, git hook    | 8h CC  |
| **Phase 6** | `telemetry-monitor.js` cron + `ALERT_TO_TASK` map                          | 6h CC  |
| **Phase 6** | Grafana dashboards (Fleet + Log Explorer)                                  | 4h CC  |
| **Phase 6** | OTEL auto-instrumentation (opt-in workers)                                 | 2h CC  |
| **Phase 6** | Prometheus alerting rules + DB migrations 077/078                          | 2h CC  |

### Integration Tests Required

File: `tests/telemetry.integration.test.js`

```javascript
// Key scenarios to test:
test('worker /metrics returns valid Prometheus text format');
test('worker /metrics includes default Node.js metrics (memory, GC, event loop)');
test('agent task completion increments agent_tasks_completed_total counter');
test('management server accepts valid JWT → returns 200');
test('management server rejects invalid JWT → returns 401');
test('telemetry-monitor creates agent task from firing Prometheus alert');
test('telemetry-monitor skips duplicate task (same alert, same machine, within 30 min)');
test('telemetry-monitor respects daily budget cap — skips if >80% spent');
test('Vector log sink delivers structured log with task_id and correlation_id intact');
test('pino mixin includes machine_id and agent_name from env');
test('OTEL auto-instrumentation attaches trace_id to outbound HTTP call');
```

---

## Part 22: Claude Max Update — Multi-Project Architecture (Added 2026-03-13)

**Context:** Merged from `distributed-infra/docs/plans/distributed-agent-system-claude-max-update.md` (2026-03-10). Claude Max subscription fundamentally changed the economics — Phase 0 and Part 20 are now obsolete (marked above).

### Multi-Project Orchestrator

The system is now multi-project. The batch orchestrator (`scripts/claude-orchestrator.sh`) serves all child projects:

```
mmo-platform/packages/orchestrator/
  claude-batch.js          # Generic batch runner (extracted from 333Method)
  claude-orchestrator.sh   # Loop + conservation mode
  claude-store.js          # Result storage
  project-registry.json    # Registered projects + their batch types
```

Each child project registers its batch types:
```json
{
  "333Method": {
    "db": "../333Method/db/sites.db",
    "batchTypes": ["proposals_email", "proposals_sms", "reword_*", "classify_replies", "extract_names", "reply_responses", "oversee", "classify_errors", "score_sites", "enrich_sites"]
  },
  "2Step": {
    "db": "../2Step/db/2step.db",
    "batchTypes": ["video_prompts", "dm_messages", "followup_messages", "classify_dm_replies"]
  }
}
```

### Unified Overseer

One Claude Code AFK session monitors all projects:

```
mmo-platform/services/overseer/
  projects.json            # Registry (DB paths, log dirs, health checks)
  monitoring-checks.sh     # Iterates over projects
  overseer.js              # Cross-project insight generation
```

The existing `monitoring-checks.sh` already uses `PROJECT_DIR` env var — the unified version loops over all registered projects.

### Claude Max Constraints for Distribution

Key constraints when distributing `claude -p` work:

1. **Cannot proxy**: `claude -p` authenticates via Claude Max subscription, not an API key. Each machine needs its own Claude session.
2. **Cannot parallelize**: `claude -p` is serial (one invocation at a time per session). The orchestrator's loop pattern handles this.
3. **Can distribute by project**: Different machines can run orchestrators for different projects.
4. **Rate limits**: Claude Max has undocumented rate limits. The orchestrator's conservation mode and frequency gates handle this.

### Updated Cost Model

| Component | Before (API-based) | Now (Claude Max) |
|-----------|-------------------|------------------|
| Proposals | ~$0.01/proposal (Sonnet) | $0 (claude -p) |
| Classification | ~$0.003/message (Haiku) | $0 (claude -p) |
| Scoring | ~$0.003/site (GPT-4o-mini) | $0.003/site (OpenRouter, optional) |
| Enrichment | ~$0.003/site (LLM) | $0 (regex) or $0.003 (LLM, optional) |
| Overseer | ~$0.05/run (Sonnet) | $0 (claude -p) |
| **Monthly total** | **~$200-500/mo** | **~$200/mo flat (subscription)** |

The subscription cost is fixed regardless of volume. Scaling up means more `claude -p` invocations, not more API spend.
