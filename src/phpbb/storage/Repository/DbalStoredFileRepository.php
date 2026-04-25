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

namespace phpbb\storage\Repository;

use Doctrine\DBAL\Connection;
use phpbb\db\Exception\RepositoryException;
use phpbb\storage\Contract\StoredFileRepositoryInterface;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;
use phpbb\storage\Enum\VariantType;

final class DbalStoredFileRepository implements StoredFileRepositoryInterface
{
	private const TABLE = 'phpbb_stored_files';

	public function __construct(
		private readonly Connection $connection,
	) {
	}

	public function findById(string $fileId): ?StoredFile
	{
		try {
			$row = $this->connection->executeQuery(
				'SELECT HEX(id) AS id, asset_type, visibility, original_name, physical_name,
				        mime_type, filesize, checksum, is_orphan, HEX(parent_id) AS parent_id,
				        variant_type, uploader_id, forum_id, created_at, claimed_at
				 FROM ' . self::TABLE . '
				 WHERE id = UNHEX(:id)
				 LIMIT 1',
				['id' => $fileId],
			)->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find stored file by ID', previous: $e);
		}
	}

	public function save(StoredFile $file): void
	{
		try {
			$this->connection->executeStatement(
				'INSERT INTO ' . self::TABLE . '
				 (id, asset_type, visibility, original_name, physical_name, mime_type, filesize,
				  checksum, is_orphan, parent_id, variant_type, uploader_id, forum_id, created_at, claimed_at)
				 VALUES
				 (UNHEX(:id), :asset_type, :visibility, :original_name, :physical_name, :mime_type, :filesize,
				  :checksum, :is_orphan, UNHEX(:parent_id), :variant_type, :uploader_id, :forum_id, :created_at, :claimed_at)',
				[
					'id'            => $file->id,
					'asset_type'    => $file->assetType->value,
					'visibility'    => $file->visibility->value,
					'original_name' => $file->originalName,
					'physical_name' => $file->physicalName,
					'mime_type'     => $file->mimeType,
					'filesize'      => $file->filesize,
					'checksum'      => $file->checksum,
					'is_orphan'     => (int) $file->isOrphan,
					'parent_id'     => $file->parentId,
					'variant_type'  => $file->variantType?->value,
					'uploader_id'   => $file->uploaderId,
					'forum_id'      => $file->forumId,
					'created_at'    => $file->createdAt,
					'claimed_at'    => $file->claimedAt,
				],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to save stored file', previous: $e);
		}
	}

	public function delete(string $fileId): void
	{
		try {
			$this->connection->executeStatement(
				'DELETE FROM ' . self::TABLE . ' WHERE id = UNHEX(:id)',
				['id' => $fileId],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete stored file', previous: $e);
		}
	}

	public function findOrphansBefore(int $timestamp): array
	{
		try {
			$rows = $this->connection->executeQuery(
				'SELECT HEX(id) AS id, asset_type, visibility, original_name, physical_name,
				        mime_type, filesize, checksum, is_orphan, HEX(parent_id) AS parent_id,
				        variant_type, uploader_id, forum_id, created_at, claimed_at
				 FROM ' . self::TABLE . '
				 WHERE is_orphan = 1 AND created_at < :ts',
				['ts' => $timestamp],
			)->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find orphans', previous: $e);
		}
	}

	public function markClaimed(string $fileId, int $claimedAt): void
	{
		try {
			$this->connection->executeStatement(
				'UPDATE ' . self::TABLE . ' SET is_orphan = 0, claimed_at = :claimed_at WHERE id = UNHEX(:id)',
				['id' => $fileId, 'claimed_at' => $claimedAt],
			);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to mark file as claimed', previous: $e);
		}
	}

	public function findVariants(string $parentId): array
	{
		try {
			$rows = $this->connection->executeQuery(
				'SELECT HEX(id) AS id, asset_type, visibility, original_name, physical_name,
				        mime_type, filesize, checksum, is_orphan, HEX(parent_id) AS parent_id,
				        variant_type, uploader_id, forum_id, created_at, claimed_at
				 FROM ' . self::TABLE . '
				 WHERE parent_id = UNHEX(:parent_id)',
				['parent_id' => $parentId],
			)->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find file variants', previous: $e);
		}
	}

	private function hydrate(array $row): StoredFile
	{
		return new StoredFile(
			id:           strtolower((string) $row['id']),
			assetType:    AssetType::from($row['asset_type']),
			visibility:   FileVisibility::from($row['visibility']),
			originalName: $row['original_name'],
			physicalName: $row['physical_name'],
			mimeType:     $row['mime_type'],
			filesize:     (int) $row['filesize'],
			checksum:     $row['checksum'],
			isOrphan:     (bool) $row['is_orphan'],
			parentId:     isset($row['parent_id']) ? strtolower((string) $row['parent_id']) : null,
			variantType:  isset($row['variant_type']) ? VariantType::tryFrom($row['variant_type']) : null,
			uploaderId:   (int) $row['uploader_id'],
			forumId:      (int) $row['forum_id'],
			createdAt:    (int) $row['created_at'],
			claimedAt:    isset($row['claimed_at']) ? (int) $row['claimed_at'] : null,
		);
	}
}
