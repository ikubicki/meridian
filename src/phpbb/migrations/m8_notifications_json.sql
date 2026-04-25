-- ============================================================
-- Migration: m8_notifications_json
-- Target DB: MariaDB 10.4+ / MySQL 5.7+
-- Description: M8 Notifications — upgrade notification_data to JSON,
--              replace single `user` index with composite `user_read_time`,
--              and seed built-in notification type rows.
--
-- Rollback instructions (execute in reverse order):
--   1. DELETE FROM phpbb_notification_types
--        WHERE notification_type_name IN ('notification.type.post', 'notification.type.topic')
--        AND notification_type_id NOT IN (<ids_that_existed_before>);
--      (Note: these rows typically pre-exist from the phpBB3 data import — verify before deleting)
--   2. ALTER TABLE phpbb_notifications
--        DROP INDEX user_read_time,
--        ADD INDEX `user` (user_id, notification_read);
--   3. ALTER TABLE phpbb_notifications
--        MODIFY COLUMN notification_data TEXT NOT NULL;
-- ============================================================

-- Step 1: Upgrade notification_data column from TEXT to JSON
-- In MariaDB, JSON is an alias for LONGTEXT with a JSON validity constraint.
ALTER TABLE phpbb_notifications
	MODIFY COLUMN notification_data JSON NOT NULL;

-- Step 2: Replace the old two-column `user` index with the three-column
--         `user_read_time` composite index optimised for list queries
--         that filter by user_id, then sort/filter by read state and time.
ALTER TABLE phpbb_notifications
	DROP INDEX `user`,
	ADD INDEX user_read_time (user_id, notification_read, notification_time);

-- Step 3: Seed built-in notification type rows.
-- INSERT IGNORE is safe to run multiple times — it skips rows whose
-- notification_type_name already exists (UNIQUE constraint).
INSERT IGNORE INTO phpbb_notification_types (notification_type_name, notification_type_enabled)
VALUES
	('notification.type.post',  1),
	('notification.type.topic', 1);
