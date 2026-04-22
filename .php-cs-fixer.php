<?php

/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
	->in([
		__DIR__ . '/src/phpbb',
		__DIR__ . '/tests/phpbb',
	])
	->name('*.php')
	->notPath('vendor');

return (new Config())
	->setIndent("\t")
	->setLineEnding("\n")
	->setRiskyAllowed(true)
	->setFinder($finder)
	->setRules([
		'@PSR12'                         => true,
		// phpBB uses tabs, not spaces
		'indentation_type'               => true,
		// No closing PHP tag
		'no_closing_tag'                 => true,
		// Declare strict types at the top
		'declare_strict_types'           => true,
		// Blank line before return
		'blank_line_before_statement'    => ['statements' => ['return', 'throw', 'yield']],
		// Single quotes preferred
		'single_quote'                   => true,
		// Trailing commas in multiline arrays
		'trailing_comma_in_multiline'    => ['elements' => ['arrays']],
		// No unused imports
		'no_unused_imports'              => true,
		// Ordered imports (alphabetical)
		'ordered_imports'                => ['sort_algorithm' => 'alpha'],
		// Array syntax — short []
		'array_syntax'                   => ['syntax' => 'short'],
		// Cast alignment
		'cast_spaces'                    => ['space' => 'single'],
		// Null coalescing assignment
		'assign_null_coalescing_to_coalesce_equal' => true,
		// No superfluous phpdoc tags
		'no_superfluous_phpdoc_tags'     => ['allow_mixed' => true],
	]);
