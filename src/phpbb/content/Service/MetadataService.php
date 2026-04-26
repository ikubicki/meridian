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

namespace phpbb\content\Service;

use Doctrine\DBAL\Connection;
use phpbb\content\Contract\MetadataPluginInterface;
use phpbb\content\Contract\MetadataServiceInterface;
use phpbb\content\DTO\ContentContext;
use phpbb\db\Exception\RepositoryException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class MetadataService implements MetadataServiceInterface
{
	/**
	 * @param iterable<MetadataPluginInterface> $plugins
	 */
	public function __construct(
		#[AutowireIterator('phpbb.metadata_plugin')] private readonly iterable $plugins,
		private readonly Connection $connection,
	) {
	}

	public function collectForPost(string $content, ContentContext $context): array
	{
		$metadata = [];

		foreach ($this->plugins as $plugin) {
			$metadata = array_merge($metadata, $plugin->extractMetadata($content, $context));
		}

		return $metadata;
	}

	public function saveForPost(int $postId, array $metadata): void
	{
		if (empty($metadata)) {
			return;
		}

		$json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

		try {
			$qb      = $this->connection->createQueryBuilder();
			$affected = (int) $qb->update('phpbb_posts')
				->set('metadata', ':metadata')
				->where($qb->expr()->eq('post_id', ':postId'))
				->setParameter('metadata', $json)
				->setParameter('postId', $postId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException($e->getMessage(), (int) $e->getCode(), $e);
		}

		if ($affected === 0) {
			throw new \InvalidArgumentException("Post {$postId} not found when saving metadata");
		}
	}
}
