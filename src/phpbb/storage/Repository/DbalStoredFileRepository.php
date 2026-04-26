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
			$row = $this->buildSelectBase()
				->where('id = :id')
				->setParameter('id', $fileId)
				->setMaxResults(1)
				->executeQuery()
				->fetchAssociative();

			return $row !== false ? $this->hydrate($row) : null;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find stored file by ID', previous: $e);
		}
	}

	public function save(StoredFile $file): void
	{
		try {
			$this->connection->createQueryBuilder()
				->insert(self::TABLE)
				->values([
					'id'            => ':id',
					'asset_type'    => ':assetType',
					'visibility'    => ':visibility',
					'original_name' => ':originalName',
					'physical_name' => ':physicalName',
					'mime_type'     => ':mimeType',
					'filesize'      => ':filesize',
					'checksum'      => ':checksum',
					'is_orphan'     => ':isOrphan',
					'parent_id'     => ':parentId',
					'variant_type'  => ':variantType',
					'uploader_id'   => ':uploaderId',
					'forum_id'      => ':forumId',
					'created_at'    => ':createdAt',
					'claimed_at'    => ':claimedAt',
				])
				->setParameter('id', $file->id)
				->setParameter('assetType', $file->assetType->value)
				->setParameter('visibility', $file->visibility->value)
				->setParameter('originalName', $file->originalName)
				->setParameter('physicalName', $file->physicalName)
				->setParameter('mimeType', $file->mimeType)
				->setParameter('filesize', $file->filesize)
				->setParameter('checksum', $file->checksum)
				->setParameter('isOrphan', (int) $file->isOrphan)
				->setParameter('parentId', $file->parentId)
				->setParameter('variantType', $file->variantType?->value)
				->setParameter('uploaderId', $file->uploaderId)
				->setParameter('forumId', $file->forumId)
				->setParameter('createdAt', $file->createdAt)
				->setParameter('claimedAt', $file->claimedAt)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to save stored file', previous: $e);
		}
	}

	public function delete(string $fileId): void
	{
		try {
			$this->connection->createQueryBuilder()
				->delete(self::TABLE)
				->where('id = :id')
				->setParameter('id', $fileId)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to delete stored file', previous: $e);
		}
	}

	public function findOrphansBefore(int $timestamp): array
	{
		try {
			$rows = $this->buildSelectBase()
				->where('is_orphan = 1')
				->andWhere('created_at < :ts')
				->setParameter('ts', $timestamp)
				->executeQuery()
				->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find orphans', previous: $e);
		}
	}

	public function markClaimed(string $fileId, int $claimedAt): void
	{
		try {
			$this->connection->createQueryBuilder()
				->update(self::TABLE)
				->set('is_orphan', '0')
				->set('claimed_at', ':claimedAt')
				->where('id = :id')
				->setParameter('id', $fileId)
				->setParameter('claimedAt', $claimedAt)
				->executeStatement();
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to mark file as claimed', previous: $e);
		}
	}

	public function findVariants(string $parentId): array
	{
		try {
			$rows = $this->buildSelectBase()
				->where('parent_id = :parentId')
				->setParameter('parentId', $parentId)
				->executeQuery()
				->fetchAllAssociative();

			return array_map($this->hydrate(...), $rows);
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new RepositoryException('Failed to find file variants', previous: $e);
		}
	}

	private function buildSelectBase(): \Doctrine\DBAL\Query\QueryBuilder
	{
		return $this->connection->createQueryBuilder()
			->select(
				'id',
				'asset_type',
				'visibility',
				'original_name',
				'physical_name',
				'mime_type',
				'filesize',
				'checksum',
				'is_orphan',
				'parent_id',
				'variant_type',
				'uploader_id',
				'forum_id',
				'created_at',
				'claimed_at',
			)
			->from(self::TABLE);
	}

	private function hydrate(array $row): StoredFile
	{
		return new StoredFile(
			id:           (string) $row['id'],
			assetType:    AssetType::from($row['asset_type']),
			visibility:   FileVisibility::from($row['visibility']),
			originalName: $row['original_name'],
			physicalName: $row['physical_name'],
			mimeType:     $row['mime_type'],
			filesize:     (int) $row['filesize'],
			checksum:     $row['checksum'],
			isOrphan:     (bool) $row['is_orphan'],
			parentId:     isset($row['parent_id']) ? (string) $row['parent_id'] : null,
			variantType:  isset($row['variant_type']) ? VariantType::tryFrom($row['variant_type']) : null,
			uploaderId:   (int) $row['uploader_id'],
			forumId:      (int) $row['forum_id'],
			createdAt:    (int) $row['created_at'],
			claimedAt:    isset($row['claimed_at']) ? (int) $row['claimed_at'] : null,
		);
	}
}
