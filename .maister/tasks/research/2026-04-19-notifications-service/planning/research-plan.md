# Research Plan: phpbb\notifications Service

## Research Overview

**Research Question**: Jak zaprojektować usługę `phpbb\notifications` odpowiedzialną za powiadomienia do użytkowników, wysyłkę emaili oraz powiadomienia w aplikacji frontendowej, z lekkim REST API endpointem informującym o ilości i najnowszych powiadomieniach? (Facebook-style)

**Research Type**: Mixed (technical + literature)

**Scope & Boundaries**:
- IN: Existing notification system (manager, 21 types, 3 methods), DB schema, email delivery, REST API endpoint, cache/auth integration, frontend badge, polling/SSE/WS strategy, notification grouping
- OUT: New notification types, push notifications, Jabber modifications, admin panel, data migration

---

## Research Objectives

### O1: Understand existing notification system internals
- How does `phpbb\forums\notification\manager` orchestrate notification dispatch?
- What's the contract between notification types and delivery methods?
- How does `find_users_for_notification()` determine recipients?
- What does the board method store and how does it query?

### O2: Analyze email delivery pipeline
- How does `method/email.php` + `messenger_base.php` handle queue and dedup?
- What role does `phpbb_notification_emails` play in preventing duplicates?
- How does the cron task (`cron/task/core/queue.php`) flush the mail queue?

### O3: Map REST API infrastructure for notifications endpoint
- How are controllers registered and routed (`services_api.yml`, `routing.yml`)?
- What patterns do existing controllers follow (auth, response format, error handling)?
- How does `auth_subscriber.php` enforce authentication on API requests?

### O4: Design cache integration strategy
- How can tag-aware cache pools accelerate notification count queries?
- What invalidation events trigger cache bust for notifications?
- How to achieve <50ms response time with proper cache layering?

### O5: Evaluate real-time delivery strategies
- Polling vs SSE vs WebSocket — which fits phpBB's PHP architecture?
- What's the latency/complexity/scalability trade-off for each?
- How do Facebook/Reddit/GitHub implement notification badges?

### O6: Design notification grouping/aggregation
- How does Facebook-style grouping work (e.g., "3 people replied to your topic")?
- What DB queries efficiently aggregate grouped notifications?
- How to represent grouped notifications in the REST API response?

### O7: Define service interface and DI integration
- What's the public API of `phpbb\notifications\service`?
- How does it integrate with Symfony DI container?
- How to maintain backward compatibility with legacy notification manager?

---

## Methodology

### Primary Approach: Hybrid codebase analysis + external pattern research

| Objective | Method | Priority |
|-----------|--------|----------|
| O1 | Codebase deep-read (manager, types, methods) | HIGH |
| O2 | Codebase read (email method, messenger_base, cron queue) | HIGH |
| O3 | Codebase read (API controllers, services_api.yml, routing) | HIGH |
| O4 | Cross-reference cache service research + notification patterns | MEDIUM |
| O5 | External research (polling/SSE/WS comparison articles) | MEDIUM |
| O6 | External research (Facebook notification patterns, SQL aggregation) | MEDIUM |
| O7 | Synthesis from O1-O6 findings | LOW (derived) |

### Fallback Strategies
- If existing notification code is too complex → focus on interfaces only
- If external best practices conflict → document trade-offs for decision
- If cache integration unclear → reference cache-service research outputs

### Analysis Framework
1. **Component mapping**: What exists, what needs wrapping/replacing
2. **Interface extraction**: Public methods needed for new service
3. **Performance modeling**: Query patterns, cache hit rates, response times
4. **Integration design**: How service connects to cache, auth, DI, events

---

## Research Phases

### Phase 1: Broad Discovery (gatherers 1-3)
- Map all notification-related files, services, and configurations
- Map all API infrastructure files and patterns
- Identify integration points (cache, auth, events, DI)

### Phase 2: Targeted Reading (gatherers 1-3)
- Deep-read notification manager and key type implementations
- Deep-read email delivery pipeline and queue mechanism
- Read API controllers and auth subscriber for patterns

### Phase 3: External Research (gatherers 4-5)
- Research Facebook-style notification patterns
- Compare polling vs SSE vs WebSocket for PHP
- Review phpBB extension ecosystem for notification customizations

### Phase 4: Synthesis
- Cross-reference internal capabilities with external best practices
- Identify gaps between current system and target design
- Propose architecture for new `phpbb\notifications` service

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | codebase-notifications | Existing notification system internals (manager, types, methods, DB schema) | Read, Grep | codebase-notifications |
| 2 | codebase-api | REST API infrastructure, routing, controllers, auth integration | Read, Grep | codebase-api |
| 3 | codebase-integration | Cache service, auth service, DI container patterns, event system | Read, Grep | codebase-integration |
| 4 | external-patterns | Facebook-style notifications, REST API patterns, polling/SSE/WS comparison | WebFetch | external-patterns |
| 5 | external-phpbb | phpBB extension ecosystem, notification customization patterns, community best practices | WebFetch | external-phpbb |

### Rationale
- **3 codebase gatherers**: The notification system spans multiple subsystems (notifications, API, infrastructure) that are each substantial enough to warrant dedicated analysis
- **2 external gatherers**: The Facebook-style grouping and real-time delivery strategy require industry research separate from phpBB-specific ecosystem knowledge
- **Split external**: phpBB community patterns vs general industry patterns serve different design decisions

### Expected Outputs per Gatherer

1. **codebase-notifications**: Manager interface, type contract, method dispatch, DB schema details, event hooks
2. **codebase-api**: Controller pattern, routing config, auth enforcement, response format, error handling
3. **codebase-integration**: Cache pool usage, auth service interface, DI registration patterns, event dispatcher
4. **external-patterns**: Notification grouping algorithms, badge count APIs, real-time strategy comparison matrix
5. **external-phpbb**: Extension notification hooks, community-recommended patterns, known limitations

---

## Success Criteria

- [ ] Notification manager's full dispatch flow documented (type → method → storage)
- [ ] Email delivery pipeline understood (queue, dedup, cron flush)
- [ ] REST API controller pattern documented with auth integration
- [ ] Cache strategy defined for notification counts (<50ms target)
- [ ] Real-time delivery recommendation made (polling/SSE/WS) with rationale
- [ ] Facebook-style grouping approach designed (DB query + API response format)
- [ ] Service interface sketch with DI registration pattern
- [ ] Integration points with cache service and auth service identified

---

## Expected Final Outputs

1. **Research Report** (`outputs/research-report.md`): Complete findings organized by objective
2. **High-Level Design** (`outputs/high-level-design.md`): Proposed architecture for `phpbb\notifications`
3. **Decision Log** (`outputs/decision-log.md`): Key design decisions with rationale
4. **Solution Exploration** (`outputs/solution-exploration.md`): Trade-off analysis for key choices (real-time strategy, grouping, caching)
