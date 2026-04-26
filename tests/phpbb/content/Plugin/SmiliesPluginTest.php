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

namespace phpbb\Tests\content\Plugin;

use phpbb\content\ContentStage;
use phpbb\content\DTO\ContentContext;
use phpbb\content\Plugin\SmiliesPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SmiliesPluginTest extends TestCase
{
	private SmiliesPlugin $plugin;
	private ContentContext $ctx;

	protected function setUp(): void
	{
		$this->plugin = new SmiliesPlugin();
		$this->ctx    = new ContentContext(actorId: 1, forumId: 2, topicId: 3);
	}

	#[Test]
	public function getNameReturnsSmilies(): void
	{
		$this->assertSame('smilies', $this->plugin->getName());
	}

	#[Test]
	public function supportsPreOutputStageOnly(): void
	{
		$this->assertTrue($this->plugin->supportsStage(ContentStage::PRE_OUTPUT));
		$this->assertFalse($this->plugin->supportsStage(ContentStage::PRE_SAVE));
		$this->assertFalse($this->plugin->supportsStage(ContentStage::POST_SAVE));
	}

	public static function smiliesProvider(): array
	{
		return [
			'happy face'        => [':)',  '😊'],
			'happy face dash'   => [':-)', '😊'],
			'sad face'          => [':(',  '😞'],
			'sad face dash'     => [':-(', '😞'],
			'big grin'          => [':D',  '😄'],
			'big grin dash'     => [':-D', '😄'],
			'tongue'            => [':P',  '😛'],
			'tongue dash'       => [':-P', '😛'],
			'wink'              => [';)',  '😉'],
			'wink dash'         => [';-)', '😉'],
			'surprised'         => [':o',  '😮'],
			'surprised upper'   => [':O',  '😮'],
			'neutral'           => [':|',  '😐'],
			'neutral dash'      => [':-|', '😐'],
			'angry'             => ['>:(', '😡'],
			'very angry'        => ['>:-(', '😡'],
			'lol named'         => [':lol:',     '😂'],
			'cry named'         => [':cry:',     '😢'],
			'evil named'        => [':evil:',    '😈'],
			'twisted named'     => [':twisted:', '😈'],
			'shock named'       => [':shock:',   '😲'],
			'oops named'        => [':oops:',    '😳'],
			'roll named'        => [':roll:',    '🙄'],
			'wink named'        => [':wink:',    '😉'],
			'mrgreen named'     => [':mrgreen:', '😁'],
			'geek named'        => [':geek:',    '🤓'],
			'arrow named'       => [':arrow:',   '➡️'],
		];
	}

	#[Test]
	#[DataProvider('smiliesProvider')]
	public function convertsSmilieToEmoji(string $smilie, string $expected): void
	{
		$result = $this->plugin->process($smilie, $this->ctx);

		$this->assertSame($expected, $result);
	}

	#[Test]
	public function convertsMultipleSmiliesInText(): void
	{
		$result = $this->plugin->process('Hello :) world :D see ya ;)', $this->ctx);

		$this->assertSame('Hello 😊 world 😄 see ya 😉', $result);
	}

	#[Test]
	public function leavesContentWithoutSmiliesUnchanged(): void
	{
		$content = 'No smilies here, just plain text.';
		$result  = $this->plugin->process($content, $this->ctx);

		$this->assertSame($content, $result);
	}

	#[Test]
	public function longerPatternTakesPrecedenceOverShorter(): void
	{
		// :-D should become 😄, not 😊 + D  (:-) + D)
		$result = $this->plugin->process(':-D', $this->ctx);

		$this->assertSame('😄', $result);
	}

	#[Test]
	public function namedSmilieDoesNotConflictWithTextSmilie(): void
	{
		// :lol: should stay as one emoji, not decompose to :l + ol:
		$result = $this->plugin->process(':lol:', $this->ctx);

		$this->assertSame('😂', $result);
	}

	#[Test]
	public function convertsSmiliesInsideSentence(): void
	{
		$result = $this->plugin->process('I am so happy :D and also :cry: sometimes.', $this->ctx);

		$this->assertSame('I am so happy 😄 and also 😢 sometimes.', $result);
	}
}
