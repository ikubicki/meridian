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

namespace phpbb\search\Tokenizer;

final class NativeTokenizer
{
	public function __construct(
		private readonly int $minLength = 3,
		private readonly int $maxLength = 14,
	) {
	}

	/**
	 * @return array{must: string[], mustNot: string[], should: string[]}
	 */
	public function tokenize(string $keywords): array
	{
		$must    = [];
		$mustNot = [];
		$should  = [];

		$rawTokens = preg_split('/\s+/', trim($keywords), -1, PREG_SPLIT_NO_EMPTY);

		foreach ($rawTokens as $token) {
			$prefix = '';
			if (str_starts_with($token, '+')) {
				$prefix = '+';
				$token  = mb_substr($token, 1);
			} elseif (str_starts_with($token, '-')) {
				$prefix = '-';
				$token  = mb_substr($token, 1);
			} elseif (str_starts_with($token, '|')) {
				$prefix = '|';
				$token  = mb_substr($token, 1);
			}

			$token = preg_replace('/[+\-|()* ]+/', '', $token);
			$token = mb_strtolower($token);

			if ($token === '') {
				continue;
			}

			if (preg_match('/[\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $token)) {
				$bigrams = $this->extractBigrams($token);
				foreach ($bigrams as $bigram) {
					$this->addToken($bigram, $prefix, $must, $mustNot, $should);
				}
				continue;
			}

			$len = mb_strlen($token);
			if ($len < $this->minLength || $len > $this->maxLength) {
				continue;
			}

			$this->addToken($token, $prefix, $must, $mustNot, $should);
		}

		return ['must' => $must, 'mustNot' => $mustNot, 'should' => $should];
	}

	/** @return string[] */
	private function extractBigrams(string $text): array
	{
		$bigrams = [];
		$chars   = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$count   = count($chars);
		for ($i = 0; $i < $count - 1; $i++) {
			$bigrams[] = $chars[$i] . $chars[$i + 1];
		}

		return $bigrams;
	}

	private function addToken(string $token, string $prefix, array &$must, array &$mustNot, array &$should): void
	{
		match ($prefix) {
			'-'     => $mustNot[] = $token,
			'|'     => $should[]  = $token,
			default => $must[]    = $token,
		};
	}
}
