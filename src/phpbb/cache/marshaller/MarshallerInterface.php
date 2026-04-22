<?php

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

declare(strict_types=1);

namespace phpbb\cache\marshaller;

/**
 * Value serialisation / deserialisation contract.
 *
 * The marshaller sits between the cache pool (which deals in typed PHP values)
 * and the backend (which stores raw strings).  Implementations must be
 * symmetric: unmarshall(marshall($v)) === $v for all supported types.
 */
interface MarshallerInterface
{
	/**
	 * Serialise any PHP value to a storable string blob.
	 *
	 * @throws \InvalidArgumentException when $value cannot be serialised
	 */
	public function marshall(mixed $value): string;

	/**
	 * Deserialise a blob back to a PHP value.
	 *
	 * @throws \InvalidArgumentException when $data is corrupt / unexpected format
	 */
	public function unmarshall(string $data): mixed;
}
