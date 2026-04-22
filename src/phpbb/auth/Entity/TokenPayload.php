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

namespace phpbb\auth\Entity;

final readonly class TokenPayload
{
	public function __construct(
		public string $iss,
		public int $sub,
		public string $aud,
		public int $iat,
		public int $exp,
		public string $jti,
		public int $gen,
		public int $pv,
		public int $utype,
		public string $flags,
	) {
	}

	/**
	 * Constructs a TokenPayload from a decoded JWT claims stdClass object.
	 *
	 * Casts sub, gen, pv, utype to int and flags to string to guard against
	 * libraries that return claim values as strings.
	 */
	public static function fromStdClass(\stdClass $claims): self
	{
		return new self(
			iss:   (string) $claims->iss,
			sub:   (int) $claims->sub,
			aud:   (string) $claims->aud,
			iat:   (int) $claims->iat,
			exp:   (int) $claims->exp,
			jti:   (string) $claims->jti,
			gen:   (int) ($claims->gen ?? 0),
			pv:    (int) ($claims->pv ?? 0),
			utype: (int) ($claims->utype ?? 0),
			flags: (string) ($claims->flags ?? ''),
		);
	}
}
