# Counter Pattern Standard

Standard pattern for denormalized counters across all phpBB services.

---

## Pattern: Tiered Counter

All denormalized count columns (e.g., `forum_topics`, `forum_posts`, `unread_count`) use the same tiered approach.

### Architecture

```
Write Path:                                 Read Path:
─────────────────                           ─────────────────
mutation occurs                             query counter
    │                                           │
    ▼                                           ▼
increment hot counter ───────────────►  read hot counter (cache)
    │                                       │ (miss)
    ▼                                       ▼
flush to cold counter ◄──── periodic ── read cold counter (DB)
    (DB column)               job
```

### Levels

1. **Hot Counter**: Cache key holding the current value. Updated on every mutation. Source of truth for reads.
2. **Cold Counter**: DB column storing the persisted value. Updated via periodic flush or on cache miss.
3. **Recalculation**: Background cron job that rebuilds counters from source data (`COUNT(*)`) for self-healing.

### Cache Key Convention

```
counter.{service}.{entity_id}.{field}
```

Examples:
- `counter.hierarchy.42.topic_count` — forum 42's topic count
- `counter.hierarchy.42.post_count` — forum 42's post count
- `counter.messaging.17.unread_count` — conversation 17's unread count

### Flush Strategies

| Strategy | Description | Use When |
|----------|-------------|----------|
| **Request-count** | Flush after N increments | High-traffic counters |
| **Time-based** | Flush every M seconds | Low-traffic, consistency matters |
| **Hybrid** | Flush on N increments OR M seconds, whichever comes first | Default recommended |

Default: flush every **100 increments** or **60 seconds**.

### Recalculation Job

Every counter MUST have a corresponding recalculation method:

```php
// In the owning service's repository
public function recalculateCounters(int $entityId): void
{
	$count = $this->db->prepare('SELECT COUNT(*) FROM phpbb_topics WHERE forum_id = ?');
	$count->execute([$entityId]);
	$actual = (int) $count->fetchColumn();

	$this->db->prepare('UPDATE phpbb_forums SET forum_topics = ? WHERE forum_id = ?')
		->execute([$actual, $entityId]);

	$this->cache->set("counter.hierarchy.{$entityId}.topic_count", $actual);
}
```

Recalculation runs as a cron task (configurable interval, default: daily).

### When to Use

- Any column that stores a count derived from another table's rows
- Any aggregate that would be expensive to compute on every read
- NOT for columns that are source data themselves

### Services Using This Pattern

| Service | Counter | Source |
|---------|---------|--------|
| Hierarchy | `forum_topics`, `forum_posts`, `forum_last_post_*` | Threads events |
| Threads | `topic_replies`, `topic_views` | Posts table, view tracking |
| Messaging | `unread_count`, `message_count` | Messages table |
| Notifications | `unread_notifications` | Notifications table |
