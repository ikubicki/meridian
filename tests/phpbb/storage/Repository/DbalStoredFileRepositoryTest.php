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

namespace phpbb\Tests\storage\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use phpbb\db\Exception\RepositoryException;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;
use phpbb\storage\Repository\DbalStoredFileRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbalStoredFileRepositoryTest extends TestCase
{
	private Connection $connection;
	private DbalStoredFileRepository $repository;

	protected function setUp(): void
	{
		$this->connection = $this->createMock(Connection::class);
		$this->repository = new DbalStoredFileRepository($this->connection);
	}

	#[Test]
	public function find_by_id_returns_null_when_not_found(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAssociative')->willReturn(false);
		$this->connection->method('executeQuery')->willReturn($result);

		$file = $this->repository->findById('abc123');

		$this->assertNull($file);
	}

	#[Test]
	public function find_by_id_returns_stored_file_when_found(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAssociative')->willReturn($this->makeRow());
		$this->connection->method('executeQuery')->willReturn($result);

		$file = $this->repository->findById('abc123');

		$this->assertInstanceOf(StoredFile::class, $file);
		$this->assertSame('abc123', $file->id);
		$this->assertSame(AssetType::Avatar, $file->assetType);
		$this->assertSame(FileVisibility::Public, $file->visibility);
		$this->assertTrue($file->isOrphan);
	}

	#[Test]
	public function save_executes_insert_statement(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->willReturn(1);

		$this->repository->save($this->makeStoredFile());
	}

	#[Test]
	public function delete_executes_delete_statement(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->with($this->stringContains('DELETE'));

		$this->repository->delete('abc123');
	}

	#[Test]
	public function find_orphans_before_returns_array(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAllAssociative')->willReturn([$this->makeRow()]);
		$this->connection->method('executeQuery')->willReturn($result);

		$orphans = $this->repository->findOrphansBefore(time());

		$this->assertCount(1, $orphans);
		$this->assertInstanceOf(StoredFile::class, $orphans[0]);
	}

	#[Test]
	public function mark_claimed_executes_update_statement(): void
	{
		$this->connection->expects($this->once())
			->method('executeStatement')
			->with($this->stringContains('UPDATE'));

		$this->repository->markClaimed('abc123', time());
	}

	#[Test]
	public function find_variants_returns_empty_array_when_none(): void
	{
		$result = $this->createMock(Result::class);
		$result->method('fetchAllAssociative')->willReturn([]);
		$this->connection->method('executeQuery')->willReturn($result);

		$variants = $this->repository->findVariants('parent123');

		$this->assertSame([], $variants);
	}

	#[Test]
	public function save_wraps_dbal_exception_in_repository_exception(): void
	{
		$this->connection->method('executeStatement')
			->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('Simulated DBAL error'));

		$this->expectException(RepositoryException::class);
		$this->repository->save($this->makeStoredFile());
	}

	private function makeRow(): array
	{
		return [
			'id'            => 'ABC123',
			'asset_type'    => 'avatar',
			'visibility'    => 'public',
			'original_name' => 'avatar.png',
			'physical_name' => 'abc123',
			'mime_type'     => 'image/png',
			'filesize'      => '1024',
			'checksum'      => str_repeat('0', 64),
			'is_orphan'     => '1',
			'parent_id'     => null,
			'variant_type'  => null,
			'uploader_id'   => '5',
			'forum_id'      => '0',
			'created_at'    => '1000000',
			'claimed_at'    => null,
		];
	}

	private function makeStoredFile(): StoredFile
	{
		return new StoredFile(
			id:           'abc123',
			assetType:    AssetType::Avatar,
			visibility:   FileVisibility::Public,
			originalName: 'avatar.png',
			physicalName: 'abc123',
			mimeType:     'image/png',
			filesize:     1024,
			checksum:     str_repeat('0', 64),
			isOrphan:     true,
			parentId:     null,
			variantType:  null,
			uploaderId:   5,
			forumId:      0,
			createdAt:    1000000,
			claimedAt:    null,
		);
	}
}
