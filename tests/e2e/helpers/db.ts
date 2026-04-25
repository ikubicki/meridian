/**
 *
 * This file is part of the phpBB4 "Meridian" package.
 *
 * @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

/**
 * E2E database helper
 *
 * Provides typed async wrappers around MariaDB for test seeding and cleanup.
 * Connection target is configurable via environment variables; defaults match
 * the docker-compose.yml port mapping (localhost:13306 → container:3306).
 *
 * The on-disk notification cache directory is volume-mounted from the host
 * (./cache → /var/www/phpbb/cache), so flushNotificationCache() can remove
 * stale entries by operating directly on the host filesystem.
 */

import mysql, { RowDataPacket } from 'mysql2/promise';
import * as fs   from 'fs';
import * as path from 'path';

// Cache dir is volume-mounted directly from the project root.
const CACHE_DIR = path.resolve(__dirname, '../../../cache/phpbb4/production/phpbb4');

// Lazy-initialised connection pool — shared within one Playwright worker process.
let pool: mysql.Pool | null = null;

function getPool(): mysql.Pool {
	if (pool === null) {
		pool = mysql.createPool({
			host:               process.env.DB_HOST     ?? '127.0.0.1',
			port:               parseInt(process.env.DB_PORT ?? '13306', 10),
			user:               process.env.DB_USER     ?? 'phpbb',
			password:           process.env.DB_PASSWORD ?? 'phpbb',
			database:           process.env.DB_NAME     ?? 'phpbb',
			waitForConnections: true,
			connectionLimit:    5,
		});
	}

	return pool;
}

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

export interface NotificationRow {
	/** notification_type_id (1 = topic, 5 = post, etc.) */
	typeId:      number;
	/** item_id — must be unique per test run to avoid cross-test collisions */
	itemId:      number;
	/** item_parent_id — typically the topic / parent entity id */
	parentId:    number;
	/** user_id that owns the notification */
	userId:      number;
	/** How many seconds ago the notification was created (default: 60) */
	timeOffset?: number;
}

// ---------------------------------------------------------------------------
// DB helpers
// ---------------------------------------------------------------------------

/**
 * Insert unread notification rows using INSERT IGNORE (idempotent).
 *
 * @returns Map of itemId → notification_id assigned by the DB.
 */
export async function seedNotifications(
	rows: NotificationRow[],
): Promise<Map<number, number>> {
	const conn  = await getPool().getConnection();
	const idMap = new Map<number, number>();

	try {
		for (const row of rows) {
			await conn.execute(
				`INSERT IGNORE INTO phpbb_notifications
				 (notification_type_id, item_id, item_parent_id, user_id, notification_read, notification_time, notification_data)
				 VALUES (?, ?, ?, ?, 0, UNIX_TIMESTAMP() - ?, '{}')`,
				[row.typeId, row.itemId, row.parentId, row.userId, row.timeOffset ?? 60],
			);

			const [found] = await conn.execute<RowDataPacket[]>(
				`SELECT notification_id FROM phpbb_notifications
				 WHERE item_id = ? AND user_id = ?`,
				[row.itemId, row.userId],
			);

			if (found.length > 0) {
				idMap.set(row.itemId, found[0]['notification_id'] as number);
			}
		}
	} finally {
		conn.release();
	}

	return idMap;
}

/**
 * Delete notification rows by their item_ids for a given user.
 */
export async function clearNotifications(itemIds: number[], userId: number): Promise<void> {
	if (itemIds.length === 0) {
		return;
	}

	const placeholders = itemIds.map(() => '?').join(', ');

	await getPool().execute(
		`DELETE FROM phpbb_notifications WHERE item_id IN (${placeholders}) AND user_id = ?`,
		[...itemIds, userId],
	);
}

/**
 * Remove all *.cache files from the on-disk notification cache directory.
 *
 * The cache/ directory is volume-mounted, so deleting files here is
 * equivalent to deleting them inside the container.
 *
 * Use this whenever you seed notifications that bypass the API (direct SQL),
 * to prevent stale cached counts from masking the new data.
 */
export function flushNotificationCache(): void {
	if (!fs.existsSync(CACHE_DIR)) {
		return;
	}

	for (const file of fs.readdirSync(CACHE_DIR)) {
		if (file.endsWith('.cache')) {
			fs.unlinkSync(path.join(CACHE_DIR, file));
		}
	}
}

/**
 * Close the connection pool.  Call once in the outermost afterAll of each
 * spec file that uses this helper.
 */
export async function closePool(): Promise<void> {
	if (pool !== null) {
		await pool.end();
		pool = null;
	}
}
