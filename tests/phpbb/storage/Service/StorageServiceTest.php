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

namespace phpbb\Tests\storage\Service;

use Doctrine\DBAL\Connection;
use phpbb\storage\Adapter\StorageAdapterFactory;
use phpbb\storage\Contract\OrphanServiceInterface;
use phpbb\storage\Contract\QuotaServiceInterface;
use phpbb\storage\Contract\StoredFileRepositoryInterface;
use phpbb\storage\Contract\UrlGeneratorInterface;
use phpbb\storage\DTO\ClaimContext;
use phpbb\storage\DTO\StoreFileRequest;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;
use phpbb\storage\Exception\FileNotFoundException;
use phpbb\storage\Exception\OrphanClaimException;
use phpbb\storage\Exception\QuotaExceededException;
use phpbb\storage\Exception\UploadValidationException;
use phpbb\storage\StorageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class StorageServiceTest extends TestCase
{
	private StoredFileRepositoryInterface $fileRepo;
	private QuotaServiceInterface $quotaService;
	private OrphanServiceInterface $orphanService;
	private UrlGeneratorInterface $urlGenerator;
	private StorageAdapterFactory $adapterFactory;
	private Connection $connection;
	private EventDispatcherInterface $dispatcher;
	private StorageService $service;
	private string $tmpFile;

	protected function setUp(): void
	{
		$this->fileRepo       = $this->createMock(StoredFileRepositoryInterface::class);
		$this->quotaService   = $this->createMock(QuotaServiceInterface::class);
		$this->orphanService  = $this->createMock(OrphanServiceInterface::class);
		$this->urlGenerator   = $this->createMock(UrlGeneratorInterface::class);
		$this->adapterFactory = new StorageAdapterFactory(sys_get_temp_dir());
		$this->connection     = $this->createMock(Connection::class);
		$this->dispatcher     = $this->createMock(EventDispatcherInterface::class);

		$this->service = new StorageService(
			fileRepo:       $this->fileRepo,
			quotaService:   $this->quotaService,
			orphanService:  $this->orphanService,
			urlGenerator:   $this->urlGenerator,
			adapterFactory: $this->adapterFactory,
			connection:     $this->connection,
			dispatcher:     $this->dispatcher,
		);

		// Create a real temporary file for store() calls
		$this->tmpFile = tempnam(sys_get_temp_dir(), 'phpbb_test_');
		file_put_contents($this->tmpFile, 'test content');
	}

	protected function tearDown(): void
	{
		if (file_exists($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	#[Test]
	public function store_throws_validation_exception_when_mime_empty(): void
	{
		$request = new StoreFileRequest(
			assetType:    AssetType::Avatar,
			uploaderId:   1,
			forumId:      0,
			tmpPath:      $this->tmpFile,
			originalName: 'avatar.jpg',
			mimeType:     '',
			filesize:     12,
		);

		$this->expectException(UploadValidationException::class);
		$this->service->store($request);
	}

	#[Test]
	public function store_throws_validation_exception_when_filesize_zero(): void
	{
		$request = new StoreFileRequest(
			assetType:    AssetType::Avatar,
			uploaderId:   1,
			forumId:      0,
			tmpPath:      $this->tmpFile,
			originalName: 'avatar.jpg',
			mimeType:     'image/jpeg',
			filesize:     0,
		);

		$this->expectException(UploadValidationException::class);
		$this->service->store($request);
	}

	#[Test]
	public function store_throws_quota_exceeded_when_quota_service_throws(): void
	{
		$this->quotaService
			->method('checkAndReserve')
			->willThrowException(new QuotaExceededException('Quota exceeded'));

		$request = new StoreFileRequest(
			assetType:    AssetType::Attachment,
			uploaderId:   1,
			forumId:      1,
			tmpPath:      $this->tmpFile,
			originalName: 'doc.pdf',
			mimeType:     'application/pdf',
			filesize:     100,
		);

		$this->expectException(QuotaExceededException::class);
		$this->service->store($request);
	}

	#[Test]
	public function store_saves_file_and_returns_event_collection(): void
	{
		$this->quotaService->method('checkAndReserve');
		$this->connection->method('beginTransaction');
		$this->connection->method('commit');
		$this->fileRepo->method('save');
		$this->dispatcher->method('dispatch')->willReturnArgument(0);

		$request = new StoreFileRequest(
			assetType:    AssetType::Avatar,
			uploaderId:   5,
			forumId:      0,
			tmpPath:      $this->tmpFile,
			originalName: 'avatar.png',
			mimeType:     'image/png',
			filesize:     strlen('test content'),
		);

		$events = $this->service->store($request);

		$this->assertCount(1, iterator_to_array($events));
	}

	#[Test]
	public function retrieve_throws_file_not_found_when_absent(): void
	{
		$this->fileRepo->method('findById')->willReturn(null);

		$this->expectException(FileNotFoundException::class);
		$this->service->retrieve('nonexistent-id');
	}

	#[Test]
	public function retrieve_returns_stored_file(): void
	{
		$file = $this->makeStoredFile();
		$this->fileRepo->method('findById')->with('abc')->willReturn($file);

		$result = $this->service->retrieve('abc');

		$this->assertSame($file, $result);
	}

	#[Test]
	public function claim_throws_when_file_already_claimed(): void
	{
		$file = $this->makeStoredFile(isOrphan: false);
		$this->fileRepo->method('findById')->willReturn($file);

		$this->expectException(OrphanClaimException::class);
		$this->service->claim(new ClaimContext('abc', 1, 'post', 10));
	}

	#[Test]
	public function claim_marks_file_and_returns_event(): void
	{
		$file = $this->makeStoredFile(isOrphan: true);
		$this->fileRepo->method('findById')->willReturn($file);
		$this->fileRepo->method('markClaimed');
		$this->dispatcher->method('dispatch')->willReturnArgument(0);

		$events = $this->service->claim(new ClaimContext('abc', 1, 'post', 10));

		$this->assertCount(1, iterator_to_array($events));
	}

	#[Test]
	public function get_url_delegates_to_url_generator(): void
	{
		$file = $this->makeStoredFile();
		$this->fileRepo->method('findById')->willReturn($file);
		$this->urlGenerator->method('generateUrl')->willReturn('http://example.com/file');

		$url = $this->service->getUrl('abc');

		$this->assertSame('http://example.com/file', $url);
	}

	private function makeStoredFile(bool $isOrphan = true): StoredFile
	{
		return new StoredFile(
			id:           'abc',
			assetType:    AssetType::Avatar,
			visibility:   FileVisibility::Public,
			originalName: 'avatar.png',
			physicalName: 'abc',
			mimeType:     'image/png',
			filesize:     100,
			checksum:     str_repeat('0', 64),
			isOrphan:     $isOrphan,
			parentId:     null,
			variantType:  null,
			uploaderId:   1,
			forumId:      0,
			createdAt:    1000000,
			claimedAt:    null,
		);
	}
}
