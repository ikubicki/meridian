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

namespace phpbb\plugins;

use phpbb\content\ContentStage;
use phpbb\content\DTO\ContentContext;
use phpbb\threads\Contract\ThreadsPluginInterface;

/**
 * Converts phpBB smilies text codes to Unicode emoji.
 * Runs at PRE_OUTPUT so emoji are displayed but raw codes are stored.
 */
final class SmiliesPlugin implements ThreadsPluginInterface
{
	/**
	 * Map of phpBB smilie codes → Unicode emoji.
	 * Longer/more-specific patterns must come before shorter ones
	 * so str_replace processes them first.
	 */
	private const SMILIES = [
		// Named smilies (phpBB defaults)
		':twisted:'  => '😈',
		':mrgreen:'  => '😁',
		':shock:'    => '😲',
		':oops:'     => '😳',
		':roll:'     => '🙄',
		':wink:'     => '😉',
		':lol:'      => '😂',
		':evil:'     => '😈',
		':cry:'      => '😢',
		':arrow:'    => '➡️',
		':geek:'     => '🤓',
		':ugeek:'    => '🤓',

		// Classic text smilies — longer/more-specific patterns first
		':-D'  => '😄',
		':-P'  => '😛',
		'>:-(' => '😡',
		':-)'  => '😊',
		':-('  => '😞',
		';-)'  => '😉',
		':-o'  => '😮',
		':-O'  => '😮',
		':-|'  => '😐',
		'>:('  => '😡',
		':D'   => '😄',
		':P'   => '😛',
		':)'   => '😊',
		':('   => '😞',
		';)'   => '😉',
		':o'   => '😮',
		':O'   => '😮',
		':|'   => '😐',
		':?:'  => '😕',
		':!:'  => '❗',
	];

	public function getName(): string
	{
		return 'smilies';
	}

	public function supportsStage(ContentStage $stage): bool
	{
		return $stage === ContentStage::PRE_OUTPUT;
	}

	public function process(string $content, ContentContext $context): string
	{
		return str_replace(
			array_keys(self::SMILIES),
			array_values(self::SMILIES),
			$content,
		);
	}
}
