# Availability query plan: measured before/after

Measured on MySQL 8.0.45 on 2026-09-11. The application database had zero
booking rows, so the measurements use the isolated, reproducible one-million-row
InnoDB fixture in `docs/availability-query-benchmark.sql`. No application rows
were modified.

## Captured query

`OneToOneBookingStrategy::isSlotAvailability()` compiles to the equivalent of:

```sql
SELECT NOT EXISTS (
    SELECT *
    FROM bookings
    WHERE slot_id = 7
      AND status IN ('pending', 'confirmed')
      AND deleted_at IS NULL
) AS slot_is_available;
```

`deleted_at IS NULL` is added by Eloquent's soft-delete scope. The fixture's hot
slot contains 100,000 historical canceled rows and no blocking row, forcing the
query to prove absence rather than getting an artificially cheap early exit.

## Before

The baseline has the foreign-key-style single-column index on `slot_id`.

```text
Limit: 1 row                           actual 414 ms, rows=0
  Filter status IN (...) AND deleted_at IS NULL
                                       estimated 8,649 rows, actual 0
    Index lookup using availability_slot_idx (slot_id=7)
                                       estimated 172,978 rows,
                                       actual 100,000 rows, 11.5..398 ms
```

MySQL uses an index lookup, not a full table scan, but it must fetch and filter
all 100,000 rows for the hot slot. The estimate is 73% above the actual row
count. There are no joins, sort, temporary table, materialization, or disk spill
in this query; the expensive work is the non-covering row lookup plus filtering.

## Recommended index and column order

```sql
CREATE INDEX bookings_slot_status_deleted_idx
    ON bookings (slot_id, status, deleted_at);
```

`slot_id` is first because it is the endpoint's required equality lookup and
normally has much higher cardinality than status. `status` is second so MySQL
can form the two small ranges for the blocking states. `deleted_at` is last: it
completes each status range and makes this existence check covering. Putting
the three-valued status first would create broad ranges spanning many slots.

The repository currently has `(slot_id, status, type)`. Its leftmost
`(slot_id, status)` prefix helps this query, but `type` is not filtered here and
the index does not cover the soft-delete predicate. If group-capacity queries
must share one index, retain that index and benchmark
`(slot_id, type, status, deleted_at)` separately rather than adding overlapping
indexes casually.

## After

The SQL is unchanged after adding `(slot_id, status, deleted_at)`.

```text
Limit: 1 row                           actual 0.0375 ms, rows=0
  Filter                               estimated 2 rows, actual 0
    Covering index range scan using availability_slot_status_deleted_idx
      (slot=7,status=pending,deleted=NULL) OR
      (slot=7,status=confirmed,deleted=NULL)
                                       actual 0.0352 ms, rows=0
```

Measured comparison:

| Metric | Before | After |
|---|---:|---:|
| Execution time at query node | 414 ms | 0.0375 ms |
| Rows examined at access node | 100,000 | 0 |
| Estimated access rows | 172,978 | 2 |
| Access | non-covering `slot_id` lookup | covering composite range |
| Sort / temp / joins | none | none |

This run is about 11,040x faster. Absolute timing depends on cache and hardware;
the stable evidence is the access-path change and elimination of 100,000 row
fetches. An earlier colder baseline run measured 1,389 ms with the same 100,000
rows examined.

## When MySQL may not use it

- The query omits `slot_id`, or applies a function/cast to it, breaking the
  leftmost prefix.
- An `OR` mixes predicates that cannot be represented as ranges on this index.
- A very unselective slot matches a large share of the table and the optimizer
  estimates a table scan is cheaper.
- Parameter types/collations force conversion, statistics are stale, or the
  table is so small that scanning is cheaper.
- A query orders by unrelated columns, needs unrelated selected columns, or
  filters primarily by another leading dimension such as `resource_id`.
- A blocking row occurs immediately: `EXISTS` can stop at its first match, so
  even a weaker index may appear fast in that particular case.

## Write and storage cost

On this fixture the secondary-index allocation increased from 25,755,648 bytes
to 53,608,448 bytes: **27,852,800 bytes (26.56 MiB), about 27.9 bytes per row**.
InnoDB allocation and production value distributions will change the exact size.

Every insert must add one B-tree entry; changes to `slot_id`, `status`, or
`deleted_at` must delete/insert an entry; deletes must remove one. This adds CPU,
redo/undo logging, buffer-pool pressure, page splits, replication traffic, and
backup/storage footprint. Status transitions and soft deletes are therefore
more expensive. The read gain should be validated against write throughput, and
an overlapping old index should be replaced only after confirming its other
consumers.

## Reproduce

Run the complete benchmark directly with the MySQL client:

```text
mysql -u <user> -p <database> < docs/availability-query-benchmark.sql
```

The SQL script recreates only the isolated benchmark table, prints both
`EXPLAIN ANALYZE` trees, and reports its final table and index allocation. It
does not read or modify application rows.
