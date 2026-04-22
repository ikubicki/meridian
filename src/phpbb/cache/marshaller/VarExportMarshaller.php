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
 * PHP serialize/unserialize marshaller.
 *
 * Objects are explicitly disallowed during deserialisation to prevent
 * object injection vulnerabilities.  Only scalar types, arrays, and null
 * survive the round-trip.
 */
class VarExportMarshaller implements MarshallerInterface
{
	public function marshall(mixed $value): string
	{
		return serialize($value);
	}

	public function unmarshall(string $data): mixed
	{
		// Suppress the PHP warning emitted by unserialize() on malformed input;
		// we detect the failure from the return value instead.
		$result = @unserialize($data, ['allowed_classes' => false]);

		if ($result === false && $data !== serialize(false)) {
			throw new \InvalidArgumentException('Failed to deserialise cache data: corrupt or unsupported format.');
		}

		return $result;
	}
}
