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

namespace phpbb\Tests\search\Tokenizer;

use phpbb\search\Tokenizer\NativeTokenizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeTokenizerTest extends TestCase
{
	private NativeTokenizer $tokenizer;

	protected function setUp(): void
	{
		$this->tokenizer = new NativeTokenizer();
	}

	#[Test]
	public function it_places_plain_words_in_must(): void
	{
		$result = $this->tokenizer->tokenize('hello world');

		$this->assertSame(['hello', 'world'], $result['must']);
		$this->assertSame([], $result['mustNot']);
		$this->assertSame([], $result['should']);
	}

	#[Test]
	public function it_places_plus_prefixed_words_in_must(): void
	{
		$result = $this->tokenizer->tokenize('+required word');

		$this->assertContains('required', $result['must']);
		$this->assertContains('word', $result['must']);
		$this->assertSame([], $result['mustNot']);
	}

	#[Test]
	public function it_places_minus_prefixed_words_in_mustNot(): void
	{
		$result = $this->tokenizer->tokenize('hello -excluded');

		$this->assertSame(['hello'], $result['must']);
		$this->assertSame(['excluded'], $result['mustNot']);
		$this->assertSame([], $result['should']);
	}

	#[Test]
	public function it_places_pipe_prefixed_words_in_should(): void
	{
		$result = $this->tokenizer->tokenize('|optional hello');

		$this->assertSame(['hello'], $result['must']);
		$this->assertSame([], $result['mustNot']);
		$this->assertSame(['optional'], $result['should']);
	}

	#[Test]
	public function it_filters_words_shorter_than_min_length(): void
	{
		$result = $this->tokenizer->tokenize('hi hello');

		$this->assertSame(['hello'], $result['must']);
	}

	#[Test]
	public function it_filters_words_longer_than_max_length(): void
	{
		$result = $this->tokenizer->tokenize('hello averylongwordthatexceedsthemaxlength');

		$this->assertSame(['hello'], $result['must']);
	}

	#[Test]
	public function it_normalises_tokens_to_lowercase(): void
	{
		$result = $this->tokenizer->tokenize('Hello WORLD');

		$this->assertSame(['hello', 'world'], $result['must']);
	}

	#[Test]
	public function it_extracts_bigrams_for_cjk_characters(): void
	{
		$result = $this->tokenizer->tokenize('日本語');

		$this->assertSame([], $result['mustNot']);
		$this->assertNotEmpty($result['must']);
		$this->assertContains('日本', $result['must']);
		$this->assertContains('本語', $result['must']);
	}
}
