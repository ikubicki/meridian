# Nested Set Core — Complete Analysis

**Source**: `src/phpbb/forums/tree/` (3 files)
**Confidence**: High (100%) — all code read directly

---

## 1. Interface: `tree_interface`

**File**: `src/phpbb/forums/tree/tree_interface.php`
**Namespace**: `phpbb\tree`

### Methods Defined

| Method | Signature | Description |
|--------|-----------|-------------|
| `insert` | `insert(array $additional_data): array` | Insert item into DB and tree |
| `delete` | `delete($item_id): array` | Delete item + subtree from DB and tree |
| `move` | `move($item_id, $delta): bool` | Move item up/down within same parent by delta steps |
| `move_down` | `move_down($item_id): bool` | Move item down by 1 |
| `move_up` | `move_up($item_id): bool` | Move item up by 1 |
| `move_children` | `move_children($current_parent_id, $new_parent_id): bool` | Move all children from one parent to another |
| `change_parent` | `change_parent($item_id, $new_parent_id): bool` | Change parent of item (reparent) |
| `get_path_and_subtree_data` | `get_path_and_subtree_data($item_id, $order_asc, $include_item): array` | Get all ancestors AND descendants |
| `get_path_data` | `get_path_data($item_id, $order_asc, $include_item): array` | Get all ancestors (path to root) |
| `get_subtree_data` | `get_subtree_data($item_id, $order_asc, $include_item): array` | Get all descendants (subtree) |

---

## 2. Base Class: `nestedset` (abstract)

**File**: `src/phpbb/forums/tree/nestedset.php`
**Namespace**: `phpbb\tree`
**Implements**: `\phpbb\tree\tree_interface`

### 2.1 Constructor & Dependencies

```php
public function __construct(
    \phpbb\db\driver\driver_interface $db,
    \phpbb\lock\db $lock,
    string $table_name,
    string $message_prefix = '',
    string $sql_where = '',
    array $item_basic_data = [],
    array $columns = []
)
```

**Dependencies**:
- `$db` — Database driver (for all SQL operations)
- `$lock` — Database lock (`\phpbb\lock\db`) for concurrency control during mutations

**Parameters**:
- `$table_name` — The DB table containing the nested set
- `$message_prefix` — Prefix for exception message language keys
- `$sql_where` — Additional SQL WHERE clause (allows multiple trees in one table; uses `sprintf` with column prefix)
- `$item_basic_data` — Column names to cache in `item_parents` (default `['*']`)
- `$columns` — Overrides for default column names (keys: `item_id`, `left_id`, `right_id`, `parent_id`, `item_parents`)

### 2.2 Properties

| Property | Default | Description |
|----------|---------|-------------|
| `$db` | — | Database driver |
| `$lock` | — | DB lock instance |
| `$table_name` | — | Table name |
| `$message_prefix` | `''` | Exception message prefix |
| `$column_item_id` | `'item_id'` | Column name for primary key |
| `$column_left_id` | `'left_id'` | Column name for left boundary |
| `$column_right_id` | `'right_id'` | Column name for right boundary |
| `$column_parent_id` | `'parent_id'` | Column name for parent reference |
| `$column_item_parents` | `'item_parents'` | Column name for cached parent data |
| `$sql_where` | `''` | Additional SQL restriction |
| `$item_basic_data` | `['*']` | Columns cached in parents |

### 2.3 Public Methods — Full Detail

#### `insert(array $additional_data): array`

**Algorithm**:
1. Call `reset_nestedset_values($additional_data)` — sets `left_id=0, right_id=0, parent_id=0, item_parents=''`, removes `item_id`
2. `INSERT INTO table` with the reset data
3. Get new `item_id` via `$db->sql_nextid()`
4. Call `add_item_to_nestedset($item_id)` — appends to end of tree
5. Return merged data

**SQL**: 1× INSERT, then `add_item_to_nestedset` does 1× SELECT MAX + 1× UPDATE

**Notes**: Items are always inserted at the ROOT level (parent_id=0) at the end of the tree. To place under a parent, use `change_parent()` afterward.

---

#### `delete($item_id): array`

**Algorithm**:
1. Call `remove_item_from_nestedset($item_id)` — returns list of removed IDs (item + entire subtree)
2. `DELETE FROM table WHERE item_id IN (removed_ids)`
3. Return removed item IDs

**SQL**: `remove_item_from_nestedset` does 1× SELECT (subtree) + 1× UPDATE (gap closing), then 1× DELETE

---

#### `move($item_id, $delta): bool`

**Algorithm (sibling reorder within same parent)**:
1. Acquire lock
2. Fetch item data by `item_id`
3. Determine direction: `delta > 0` → move_up, `delta < 0` → move_down
4. Fetch up to `abs($delta)` siblings in the movement direction (same `parent_id`, ordered by `left_id` or `right_id`)
5. Take the LAST fetched sibling as `$target` (the farthest position to swap with)
6. If no target found → already at boundary, return false
7. Calculate swap region:
   - **move_up**: `$left_id = target.left_id`, `$right_id = item.right_id`
   - **move_down**: `$left_id = item.left_id`, `$right_id = target.right_id`
8. Calculate diffs:
   - `$diff_up` = how much to subtract from items moving up
   - `$diff_down` = how much to add to items moving down
   - `$move_up_left/$move_up_right` = range of items that move up
9. Single UPDATE with nested CASE expressions:
   ```sql
   SET left_id = left_id + CASE
       WHEN left_id BETWEEN move_up_left AND move_up_right THEN -diff_up
       ELSE diff_down
   END,
   right_id = right_id + CASE ...
   WHERE left_id BETWEEN left_id AND right_id
     AND right_id BETWEEN left_id AND right_id
   ```
10. Release lock, return true

**SQL**: 1× SELECT (item), 1× SELECT (siblings), 1× UPDATE (swap)
**Locking**: Full lock acquired and released

---

#### `move_down($item_id): bool`
Delegates to `$this->move($item_id, -1)`.

#### `move_up($item_id): bool`
Delegates to `$this->move($item_id, 1)`.

---

#### `move_children($current_parent_id, $new_parent_id): bool`

**Algorithm (reparent ALL children of a node)**:
1. Validate: current != new, current != 0
2. Acquire lock
3. Fetch subtree of `$current_parent_id`
4. Remove current_parent from list → `$move_items` = children only
5. Check: parent has children (right_id - left_id > 1)
6. Check: new_parent not in move_items (prevents circular)
7. Begin transaction
8. `remove_subset($move_items, $current_parent, false, true)` — close gap, but KEEP subset left/right values
9. If new_parent_id > 0:
   - Re-fetch new_parent (may have shifted)
   - `prepare_adding_subset($move_items, $new_parent)` — open gap at new location
   - Calculate diff between old and new positions
10. If new_parent_id = 0 (move to root):
    - `SELECT MAX(right_id)` excluding moved items → calculate diff
11. UPDATE moved items: shift `left_id`/`right_id` by diff, set `parent_id` (only direct children change parent), clear `item_parents` cache
12. Commit transaction, release lock

**SQL**: 1× SELECT (subtree), 1× UPDATE (remove_subset), 1× SELECT (new parent), 1× UPDATE (prepare_adding), 1× UPDATE (final move)
**Locking**: Full lock + transaction

---

#### `change_parent($item_id, $new_parent_id): bool`

**Algorithm (reparent a single item + its subtree)**:
1. Validate: item != new_parent, item != 0
2. Acquire lock
3. Fetch subtree of `$item_id` → `$move_items` includes item itself + all descendants
4. Check: new_parent not in move_items (prevents circular reference)
5. Begin transaction
6. `remove_subset($move_items, $item, false, true)` — close gap, KEEP left/right
7. If new_parent_id > 0:
   - Re-fetch new_parent (may have shifted)
   - `prepare_adding_subset($move_items, $new_parent)` — open gap
   - Calculate diff (note: uses `right_id + 1` offset vs `move_children`'s `right_id`)
8. If new_parent_id = 0:
   - `SELECT MAX(right_id)` excluding moved items, diff = max - left_id + 1
9. UPDATE moved items: shift left/right, set `parent_id` only for the item itself (via `sql_case`), clear `item_parents`
10. Commit, release lock

**Key difference from `move_children`**: This moves the item ITSELF plus its subtree. The parent_id update uses `column_item_id = $item_id` (only the root of the moved subtree changes parent), while `move_children` uses `column_parent_id = $current_parent_id` (all direct children change parent).

**SQL**: Same pattern as `move_children`
**Locking**: Full lock + transaction

---

#### `get_path_and_subtree_data($item_id, $order_asc = true, $include_item = true): array`

**Condition**: 
```sql
i2.left_id BETWEEN i1.left_id AND i1.right_id
OR i1.left_id BETWEEN i2.left_id AND i2.right_id
```
This selects both ancestors (i2 contains i1) AND descendants (i1 contains i2).

Delegates to `get_set_of_nodes_data()`.

---

#### `get_path_data($item_id, $order_asc = true, $include_item = true): array`

**Condition**: 
```sql
i1.left_id BETWEEN i2.left_id AND i2.right_id
```
Selects nodes where i2 **contains** i1 — i.e., ancestors of item.

Delegates to `get_set_of_nodes_data()`.

---

#### `get_subtree_data($item_id, $order_asc = true, $include_item = true): array`

**Condition**: 
```sql
i2.left_id BETWEEN i1.left_id AND i1.right_id
```
Selects nodes where i1 **contains** i2 — i.e., descendants of item.

Delegates to `get_set_of_nodes_data()`.

---

#### `get_path_basic_data(array $item): array`

**Not in interface** — public method on base class.

**Algorithm**:
1. If item has no parent → return empty
2. If `item_parents` column is empty (cache miss):
   - SELECT basic columns WHERE `left_id < item.left_id AND right_id > item.right_id` (all ancestors)
   - Serialize result, UPDATE `item_parents` column for all items with same `parent_id`
3. If `item_parents` is populated → `unserialize()` it
4. Return array of parent data keyed by item_id

**Caching**: Uses `item_parents` DB column as serialized PHP cache. Cache is invalidated (set to `''`) on any tree structure change (`change_parent`, `move_children`).

**SQL**: 1× SELECT + 1× UPDATE (on cache miss), or 0 queries (cache hit)

---

#### `get_all_tree_data($order_asc = true): array`

**Not in interface** — public method on base class.

Simple `SELECT * FROM table ... ORDER BY left_id`. Returns all items keyed by item_id.

**SQL**: 1× SELECT

---

#### `regenerate_left_right_ids($new_id, $parent_id = 0, $reset_ids = false): int`

**Not in interface** — public repair method.

**Algorithm (recursive DFS rebuild)**:
1. On first call: acquire lock, begin transaction, clear `item_parents` cache for all items
2. If `$reset_ids = true`: set all `left_id=0, right_id=0, item_parents=''`
3. SELECT all items WHERE `parent_id = $parent_id`, ORDER BY `left_id, item_id`
4. For each item:
   - Set `left_id = $new_id`, increment
   - Recurse into children: `regenerate_left_right_ids($new_id, item_id)`
   - Set `right_id = $new_id`, increment
5. On first call: commit transaction, release lock
6. Return next available id

**SQL**: 3 queries per item (SELECT children, UPDATE left, UPDATE right)
**Purpose**: Repair tool — fixes corrupted left/right values based on parent_id relationships
**Warning**: Executes 3 queries per item — expensive, only for repair scenarios

---

### 2.4 Protected Methods — Detail

#### `get_sql_where($operator = 'AND', $column_prefix = ''): string`

Returns the `$sql_where` restriction prepended with operator. Uses `sprintf($sql_where, $column_prefix)` to inject column prefix for JOINs.

---

#### `acquire_lock(): bool`

1. If lock already owned → return false (no double-lock)
2. Attempt `$lock->acquire()`
3. On failure → throw `\RuntimeException` with `message_prefix . 'LOCK_FAILED_ACQUIRE'`
4. Return true

---

#### `add_item_to_nestedset($item_id): array`

1. `SELECT MAX(right_id)` from table → `$current_max_right_id`
2. Set `left_id = max + 1`, `right_id = max + 2`, `parent_id = 0`
3. UPDATE the item WHERE `item_id = $item_id AND parent_id = 0 AND left_id = 0 AND right_id = 0`
4. Return updated data if exactly 1 row affected, else empty array

**Safety**: The WHERE clause ensures only items with zero'd nested set values get updated (prevents double-insertion).

---

#### `remove_item_from_nestedset($item_id): array`

1. Validate `$item_id` is non-zero
2. Call `get_subtree_data($item_id)` to get all descendants
3. Validate item exists in result
4. Call `remove_subset($item_ids, $items[$item_id])` with `set_subset_zero = true` (default)
5. Return array of removed item IDs

---

#### `remove_subset(array $subset_items, array $bounding_item, $set_subset_zero = true): null`

**Core gap-closing algorithm**:

1. Acquire lock (if not already held)
2. Calculate `$diff = count($subset_items) * 2` (each item occupies 2 positions: left + right)
3. Build SQL conditions:
   - `$sql_is_parent`: nodes whose left ≤ bounding.right AND right ≥ bounding.right (ancestors of removed subtree)
   - `$sql_is_right`: nodes whose left > bounding.right (nodes to the right of removed subtree)
4. Build CASE expressions:
   - `left_id`: if node is to the right → subtract diff; else keep
   - `right_id`: if node is parent OR to the right → subtract diff; else keep
5. If `$set_subset_zero = true`: additionally set the subset's own left/right/parent to 0
6. If `$set_subset_zero = false`: only update non-subset items (used for moves where we need to keep original values for diff calculation)
7. Single UPDATE statement
8. Release lock if newly acquired

**Note**: Called with 4 arguments in `move_children` and `change_parent` (`false, true`), but the method only accepts 3 parameters. The 4th argument is silently ignored by PHP.

---

#### `prepare_adding_subset(array $subset_items, array $new_parent): int`

**Core gap-opening algorithm**:

1. Calculate `$diff = count($subset_items) * 2`
2. For nodes NOT in subset:
   - `left_id`: if left > new_parent.right → add diff; else keep
   - `right_id`: if right >= new_parent.right → add diff; else keep
3. Single UPDATE statement (excludes subset items)
4. Return `new_parent.right_id + diff` (the new right boundary of parent after gap opened)

**Note**: Called with 3 arguments but only accepts 2. 3rd argument silently ignored.

---

#### `reset_nestedset_values(array $item): array`

Sets `parent_id=0, left_id=0, right_id=0, item_parents=''` and removes `item_id` key. Used before INSERT to ensure clean nested set state.

---

## 3. Forum Subclass: `nestedset_forum`

**File**: `src/phpbb/forums/tree/nestedset_forum.php`
**Namespace**: `phpbb\tree`
**Extends**: `\phpbb\tree\nestedset`

### 3.1 Constructor

```php
public function __construct(
    \phpbb\db\driver\driver_interface $db,
    \phpbb\lock\db $lock,
    string $table_name
)
```

Calls parent with:
- `$message_prefix` = `'FORUM_NESTEDSET_'`
- `$sql_where` = `''` (no additional restriction — single tree per table)
- `$item_basic_data` = `['forum_id', 'forum_name', 'forum_type']` (cached in `forum_parents`)
- `$columns` = `['item_id' => 'forum_id', 'item_parents' => 'forum_parents']`

### 3.2 Column Mapping

| Abstract Column | Forum Column |
|----------------|--------------|
| `item_id` | `forum_id` |
| `left_id` | `left_id` (default) |
| `right_id` | `right_id` (default) |
| `parent_id` | `parent_id` (default) |
| `item_parents` | `forum_parents` |

### 3.3 No Additional Methods

`nestedset_forum` adds NO methods. It is purely a configuration subclass that maps the generic nested set onto the forums table schema.

---

## 4. Nested Set Algorithm Details

### 4.1 Data Model

Each node has:
- `left_id` (int): Left boundary in the tree traversal
- `right_id` (int): Right boundary in the tree traversal
- `parent_id` (int): Direct parent reference (redundant with nested set, but used for faster queries and regeneration)
- `item_parents` (text): Serialized PHP array of ancestor basic data (cache)

**Invariants**:
- For any node: `left_id < right_id`
- For a leaf node: `right_id = left_id + 1`
- Number of descendants = `(right_id - left_id - 1) / 2`
- Node A is ancestor of B iff `A.left_id < B.left_id AND A.right_id > B.right_id`
- Node A is descendant of B iff `A.left_id > B.left_id AND A.right_id < B.right_id`

### 4.2 Insertion Algorithm

1. New item inserted with `left_id=0, right_id=0, parent_id=0` (via `reset_nestedset_values`)
2. After DB INSERT, `add_item_to_nestedset`:
   - Find `MAX(right_id)` in the entire tree
   - Set `left_id = max + 1, right_id = max + 2`
   - Item becomes a new root-level leaf at the END of the tree
3. No gap creation needed — appends to the right edge

**Limitation**: Always inserts at root level. Must call `change_parent()` to place under a specific parent.

### 4.3 Deletion Algorithm

1. Get entire subtree (item + all descendants) via `get_subtree_data`
2. `remove_subset` closes the gap:
   - `diff = count(removed_items) * 2`
   - Nodes to the RIGHT of the removed subtree: `left_id -= diff, right_id -= diff`
   - Ancestor nodes: only `right_id -= diff` (they shrink)
   - Removed items: `left_id = 0, right_id = 0, parent_id = 0`
3. `DELETE FROM` the removed item IDs

### 4.4 Move (Sibling Reorder) Algorithm

Swaps two subtree regions in a single UPDATE with CASE expressions:
- Region of items being moved up: subtract `diff_up` from left/right
- Other items in the affected range: add `diff_down` to left/right
- Affects ALL nodes between the moving item and target position

### 4.5 Reparent (change_parent / move_children) Algorithm

Three-phase approach within a transaction:
1. **Remove** — `remove_subset` closes the gap where the subtree currently sits (left/right values of moved items are KEPT intact for diff calculation)
2. **Prepare** — `prepare_adding_subset` opens a gap at the new parent's position (shifts all right-side and ancestor nodes)
3. **Move** — Single UPDATE shifts the moved items' left/right by the calculated diff, updates `parent_id`, clears `item_parents` cache

### 4.6 Traversal Queries

All traversal uses a self-JOIN pattern:
```sql
SELECT i2.*
FROM table i1
LEFT JOIN table i2
    ON (condition AND sql_where)
WHERE i1.item_id = :item_id AND sql_where
ORDER BY i2.left_id ASC|DESC
```

| Query | Condition | Returns |
|-------|-----------|---------|
| `get_path_data` | `i1.left BETWEEN i2.left AND i2.right` | Ancestors (i2 "contains" i1) |
| `get_subtree_data` | `i2.left BETWEEN i1.left AND i1.right` | Descendants (i1 "contains" i2) |
| `get_path_and_subtree_data` | Both conditions OR'd | Ancestors + Descendants |

### 4.7 Concurrency Control

- All mutating operations use `\phpbb\lock\db` (database-level advisory lock)
- `acquire_lock()` is reentrant — checks `$lock->owns_lock()` to prevent deadlock
- Lock released after each operation completes
- `move_children` and `change_parent` additionally use DB transactions (`sql_transaction`)

### 4.8 Parent Cache (`item_parents`)

- `get_path_basic_data()` is the only consumer
- Stores serialized PHP array of ancestor basic data
- Cache written to DB on first access (lazy population)
- Cache invalidated (set to `''`) whenever tree structure changes:
  - `change_parent` → clears for all moved items
  - `move_children` → clears for all moved items
  - `regenerate_left_right_ids` → clears for ALL items

---

## 5. Dependency Map

```
tree_interface  ←── implements ──  nestedset (abstract)  ←── extends ──  nestedset_forum
                                       │
                                       ├── $db: \phpbb\db\driver\driver_interface
                                       └── $lock: \phpbb\lock\db
```

**External dependencies**:
- `\phpbb\db\driver\driver_interface` — All SQL operations (`sql_query`, `sql_build_array`, `sql_case`, `sql_in_set`, `sql_escape`, `sql_nextid`, etc.)
- `\phpbb\lock\db` — Advisory locking (`acquire()`, `release()`, `owns_lock()`)

**No events dispatched** — The base `nestedset` class does not use the phpBB event dispatcher. Any event dispatching would happen in consuming code, not in the tree itself.

---

## 6. Method Index (Quick Reference)

### Interface Methods (public)
| Method | Lines | Purpose |
|--------|-------|---------|
| `insert` | 124-133 | Insert new item at root |
| `delete` | 203-211 | Delete item + subtree |
| `move` | 216-326 | Reorder within parent |
| `move_down` | 331-334 | Shortcut: move($id, -1) |
| `move_up` | 339-342 | Shortcut: move($id, 1) |
| `move_children` | 347-455 | Reparent all children |
| `change_parent` | 460-559 | Reparent item + subtree |
| `get_path_and_subtree_data` | 564-570 | Ancestors + descendants |
| `get_path_data` | 575-580 | Ancestors only |
| `get_subtree_data` | 585-590 | Descendants only |

### Non-Interface Public Methods
| Method | Lines | Purpose |
|--------|-------|---------|
| `get_sql_where` | 96-99 | Build additional WHERE clause |
| `get_path_basic_data` | 639-675 | Get cached parent path data |
| `get_all_tree_data` | 683-697 | Fetch entire tree |
| `regenerate_left_right_ids` | 808-870 | Repair/rebuild tree |

### Protected Methods
| Method | Lines | Purpose |
|--------|-------|---------|
| `acquire_lock` | 107-118 | Acquire advisory lock |
| `add_item_to_nestedset` | 139-164 | Append item to tree end |
| `remove_item_from_nestedset` | 173-199 | Remove item from tree (keep in DB) |
| `remove_subset` | 705-744 | Close gap after removal |
| `prepare_adding_subset` | 747-764 | Open gap for insertion |
| `reset_nestedset_values` | 770-781 | Zero out nested set columns |
| `get_set_of_nodes_data` | 600-631 | Generic self-JOIN query |

---

## 7. Anomalies Noted

1. **Extra arguments in method calls**: `remove_subset` is called with 4 args (lines 398, 496) but only accepts 3 params. `prepare_adding_subset` is called with 3 args (lines 417, 515) but only accepts 2 params. PHP silently ignores extra arguments — no runtime error, but may indicate a refactoring artifact.

2. **insert() always adds to root**: No way to insert directly under a parent. Callers must `insert()` then `change_parent()`.

3. **Serialized PHP in DB**: `item_parents` column stores `serialize()`d data. This is a legacy pattern — modern approaches prefer JSON.

4. **get_path_basic_data cache scope**: Cache UPDATE applies to all items with the same `parent_id`, not just the queried item. This means one query populates cache for all siblings.
