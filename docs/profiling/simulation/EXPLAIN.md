# Relevant MySQL EXPLAIN output

Captured using `EXPLAIN` with the recorded SQL and bindings after each measured phase. These are optimizer estimates, not EXPLAIN ANALYZE execution counts. Full plans are also embedded in the raw JSON. Repeated indexed lookups can still cause N+1 latency.

## before � booking-index

Executions per request: **1**. First actual SQL/bindings:

```sql
select count(*) as `aggregate` from `bookings` where `customer_id` = ? and `bookings`.`deleted_at` is null
```

Bindings: `[1]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | bookings | ALL | � | 3000 | Using where |

Executions per request: **1**. First actual SQL/bindings:

```sql
select * from `bookings` where `customer_id` = ? and `bookings`.`deleted_at` is null order by `id` desc limit 50 offset 0
```

Bindings: `[1]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | bookings | range | bookings_customer_id_foreign | 3000 | Using index condition; Using where; Backward index scan |

Executions per request: **50**. First actual SQL/bindings:

```sql
select `id`, `name`, `email` from `customers` where `customers`.`id` in (1)
```

Bindings: `[]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | customers | const | PRIMARY | 1 | � |

Executions per request: **50**. First actual SQL/bindings:

```sql
select `id`, `name`, `type` from `resources` where `resources`.`id` in (50)
```

Bindings: `[]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | resources | const | PRIMARY | 1 | � |

Executions per request: **50**. First actual SQL/bindings:

```sql
select `id`, `date`, `start_time`, `end_time` from `slots` where `slots`.`id` in (3000)
```

Bindings: `[]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | slots | const | PRIMARY | 1 | � |

Executions per request: **50**. First actual SQL/bindings:

```sql
select count(*) as `aggregate` from `booking_documents` where `booking_documents`.`booking_id` = ? and `booking_documents`.`booking_id` is not null and `booking_documents`.`deleted_at` is null
```

Bindings: `[3000]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | booking_documents | ref | booking_documents_booking_id_index | 1 | Using where |

## before � non-paid

Executions per request: **1**. First actual SQL/bindings:

```sql
select * from `bookings` where `customer_id` = ? and `status` = ? and `bookings`.`deleted_at` is null order by `created_at` desc
```

Bindings: `[1,"pending"]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | bookings | ALL | � | 3000 | Using where; Using filesort |

Executions per request: **750**. First actual SQL/bindings:

```sql
select * from `slots` where `slots`.`id` in (1)
```

Bindings: `[]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | slots | const | PRIMARY | 1 | � |

Executions per request: **750**. First actual SQL/bindings:

```sql
select * from `resources` where `resources`.`id` in (1)
```

Bindings: `[]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | resources | const | PRIMARY | 1 | � |

Executions per request: **750**. First actual SQL/bindings:

```sql
select * from `customers` where `customers`.`id` in (1)
```

Bindings: `[]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | customers | const | PRIMARY | 1 | � |

## before � availability

Executions per request: **1**. First actual SQL/bindings:

```sql
select * from `slots` where `status` = ? and `date` >= ? and `date` < ? order by `date` asc, `start_time` asc
```

Bindings: `["active","2030-10-18","2030-11-18"]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | slots | range | slots_date_start_time_end_time_index | 310 | Using index condition; Using where |

Executions per request: **310**. First actual SQL/bindings:

```sql
select exists(select * from `bookings` where `bookings`.`slot_id` = ? and `bookings`.`slot_id` is not null and `status` in (?, ?) and `bookings`.`deleted_at` is null) as `exists`
```

Bindings: `[2891,"pending","confirmed"]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| PRIMARY | � | � | � | � | No tables used |
| SUBQUERY | bookings | range | bookings_slot_status_type_idx | 2 | Using index condition; Using where |

## after � booking-index

Executions per request: **1**. First actual SQL/bindings:

```sql
select count(*) as `aggregate` from `bookings` where `customer_id` = ? and `bookings`.`deleted_at` is null
```

Bindings: `[1]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | bookings | ALL | � | 3000 | Using where |

Executions per request: **1**. First actual SQL/bindings:

```sql
select `bookings`.*, (select count(*) from `booking_documents` where `bookings`.`id` = `booking_documents`.`booking_id` and `booking_documents`.`deleted_at` is null) as `documents_count` from `bookings` where `customer_id` = ? and `bookings`.`deleted_at` is null order by `id` desc limit 50 offset 0
```

Bindings: `[1]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| PRIMARY | bookings | range | bookings_customer_id_foreign | 3000 | Using index condition; Using where; Backward index scan |
| DEPENDENT SUBQUERY | booking_documents | ref | booking_documents_booking_id_index | 1 | Using where |

## after � non-paid

Executions per request: **1**. First actual SQL/bindings:

```sql
select * from `bookings` where `customer_id` = ? and `status` = ? and `bookings`.`deleted_at` is null order by `created_at` desc
```

Bindings: `[1,"pending"]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | bookings | ALL | � | 3000 | Using where; Using filesort |

## after � availability

Executions per request: **1**. First actual SQL/bindings:

```sql
select * from `slots` where `status` = ? and `date` >= ? and `date` < ? and not exists (select * from `bookings` where `slots`.`id` = `bookings`.`slot_id` and `status` in (?, ?) and `bookings`.`deleted_at` is null) order by `date` asc, `start_time` asc
```

Bindings: `["active","2030-10-18","2030-11-18","pending","confirmed"]`

| select_type | table | type | key | estimated rows | Extra |
|---|---|---|---|---:|---|
| SIMPLE | slots | range | slots_date_start_time_end_time_index | 310 | Using index condition; Using where |
| SIMPLE | bookings | ref | bookings_slot_status_type_idx | 1 | Using where; Not exists |
