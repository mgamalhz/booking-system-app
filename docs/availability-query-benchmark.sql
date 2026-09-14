-- Reproducible MySQL 8 benchmark for the one-to-one availability query.
-- Uses an isolated table and does not read or modify application data.

DROP TABLE IF EXISTS availability_booking_benchmark;

CREATE TABLE availability_booking_benchmark (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slot_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'confirmed', 'canceled') NOT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX availability_slot_idx (slot_id)
) ENGINE=InnoDB;

-- 1,000,000 rows. Slot 7 is the worst useful case: 100,000 historical rows,
-- none of which blocks availability. Other slots have mixed statuses.
INSERT INTO availability_booking_benchmark (slot_id, status, deleted_at)
SELECT
    MOD(n, 10) AS slot_id,
    CASE
        WHEN MOD(n, 10) = 7 THEN 'canceled'
        WHEN MOD(n, 3) = 0 THEN 'pending'
        WHEN MOD(n, 3) = 1 THEN 'confirmed'
        ELSE 'canceled'
    END AS status,
    NULL AS deleted_at
FROM (
    SELECT
        ones.n
        + tens.n * 10
        + hundreds.n * 100
        + thousands.n * 1000
        + ten_thousands.n * 10000
        + hundred_thousands.n * 100000 AS n
    FROM
        (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) ones
        CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) tens
        CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) hundreds
        CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) thousands
        CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) ten_thousands
        CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) hundred_thousands
) sequence;

ANALYZE TABLE availability_booking_benchmark;

-- Captured equivalent of Eloquent's doesntExist() subquery (before).
EXPLAIN ANALYZE
SELECT NOT EXISTS (
    SELECT *
    FROM availability_booking_benchmark
    WHERE slot_id = 7
      AND status IN ('pending', 'confirmed')
      AND deleted_at IS NULL
) AS slot_is_available;

-- Recommended index for this exact endpoint query.
CREATE INDEX availability_slot_status_deleted_idx
    ON availability_booking_benchmark (slot_id, status, deleted_at);

ANALYZE TABLE availability_booking_benchmark;

-- Same query, unchanged (after).
EXPLAIN ANALYZE
SELECT NOT EXISTS (
    SELECT *
    FROM availability_booking_benchmark
    WHERE slot_id = 7
      AND status IN ('pending', 'confirmed')
      AND deleted_at IS NULL
) AS slot_is_available;

SHOW TABLE STATUS LIKE 'availability_booking_benchmark';

-- Optional cleanup after recording the evidence:
-- DROP TABLE availability_booking_benchmark;
