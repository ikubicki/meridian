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

namespace phpbb\Tests\api\Controller;

use phpbb\api\Controller\FilesController;
use phpbb\common\Event\DomainEventCollection;
use phpbb\storage\Contract\StorageServiceInterface;
use phpbb\storage\Entity\StoredFile;
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Enum\FileVisibility;
use phpbb\storage\Event\FileStoredEvent;
use phpbb\storage\Exception\FileNotFoundException;
use phpbb\storage\Exception\QuotaExceededException;
use phpbb\storage\Exception\UploadValidationException;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class FilesControllerTest extends TestCase
{
	private StorageServiceInterface&MockObject $storageService;
	private EventDispatcherInterface&MockObject $dispatcher;
	private FilesController $controller;
	private string $tmpFile;

	protected function setUp(): void
	{
		$this->storageService = $this->createMock(StorageServiceInterface::class);
		$this->dispatcher     = $this->createMock(EventDispatcherInterface::class);

		$this->controller = new FilesController(
			storageService: $this->storageService,
			dispatcher:     $this->dispatcher,
		);

		$this->tmpFile = tempnam(sys_get_temp_dir(), 'phpbb_ctrl_test_');
		file_put_contents($this->tmpFile, 'dummy image content');
	}

	protected function tearDown(): void
	{
		if (file_exists($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	#[Test]
	public function upload_returns_401_when_not_authenticated(): void
	{
		$request  = new Request();
		$response = $this->controller->upload($request);

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function upload_returns_400_when_no_file(): void
	{
		$request = new Request();
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->upload($request);

		$this->assertSame(400, $response->getStatusCode());
		$data = json_decode($response->getContent(), true);
		$this->assertSame('missing_file', $data['code']);
	}

	#[Test]
	public function upload_returns_400_when_invalid_asset_type(): void
	{
		$uploadedFile = new UploadedFile($this->tmpFile, 'test.jpg', 'image/jpeg', null, true);

		$request = new Request([], ['asset_type' => 'invalid_type'], [], [], ['file' => $uploadedFile]);
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->upload($request);

		$this->assertSame(400, $response->getStatusCode());
		$data = json_decode($response->getContent(), true);
		$this->assertSame('invalid_asset_type', $data['code']);
	}

	#[Test]
	public function upload_returns_400_when_quota_exceeded(): void
	{
		$uploadedFile = new UploadedFile($this->tmpFile, 'avatar.jpg', 'image/jpeg', null, true);

		$this->storageService
			->method('store')
			->willThrowException(new QuotaExceededException('Quota exceeded'));

		$request = new Request([], ['asset_type' => 'avatar', 'forum_id' => '0'], [], [], ['file' => $uploadedFile]);
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->upload($request);

		$this->assertSame(400, $response->getStatusCode());
		$data = json_decode($response->getContent(), true);
		$this->assertSame('quota_exceeded', $data['code']);
	}

	#[Test]
	public function upload_returns_400_when_validation_fails(): void
	{
		$uploadedFile = new UploadedFile($this->tmpFile, 'avatar.jpg', 'image/jpeg', null, true);

		$this->storageService
			->method('store')
			->willThrowException(new UploadValidationException('Bad file'));

		$request = new Request([], ['asset_type' => 'avatar'], [], [], ['file' => $uploadedFile]);
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->upload($request);

		$this->assertSame(400, $response->getStatusCode());
		$data = json_decode($response->getContent(), true);
		$this->assertSame('validation_error', $data['code']);
	}

	#[Test]
	public function upload_returns_201_on_success(): void
	{
		$uploadedFile = new UploadedFile($this->tmpFile, 'avatar.jpg', 'image/jpeg', null, true);

		$event = new FileStoredEvent('file-id-123', 1, 'file-id-123', AssetType::Avatar);

		$this->storageService
			->method('store')
			->willReturn(new DomainEventCollection([$event]));

		$this->storageService
			->method('getUrl')
			->willReturn('http://localhost/images/avatars/upload/file-id-123');

		$this->dispatcher->method('dispatch')->willReturnArgument(0);

		$request = new Request([], ['asset_type' => 'avatar', 'forum_id' => '0'], [], [], ['file' => $uploadedFile]);
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->upload($request);

		$this->assertSame(201, $response->getStatusCode());
		$data = json_decode($response->getContent(), true);
		$this->assertArrayHasKey('file_id', $data);
		$this->assertArrayHasKey('url', $data);
	}

	// ---------------------------------------------------------------------------
	// GET /files/{id}
	// ---------------------------------------------------------------------------

	#[Test]
	public function get_returns_404_when_file_not_found(): void
	{
		$this->storageService
			->method('retrieve')
			->willThrowException(new FileNotFoundException('not found'));

		$response = $this->controller->get('unknown', Request::create('/api/v1/files/unknown'));

		$this->assertSame(404, $response->getStatusCode());
		$body = json_decode($response->getContent(), true);
		$this->assertSame('File not found', $body['error']);
	}

	#[Test]
	public function get_returns_200_with_file_info(): void
	{
		$file = $this->makeStoredFile();
		$this->storageService->method('retrieve')->with('abc')->willReturn($file);
		$this->storageService->method('getUrl')->with('abc')->willReturn('http://example.com/images/avatars/upload/abc');

		$response = $this->controller->get('abc', Request::create('/api/v1/files/abc'));

		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode($response->getContent(), true);
		$this->assertSame('abc', $body['file_id']);
		$this->assertSame('avatar', $body['asset_type']);
		$this->assertSame('public', $body['visibility']);
		$this->assertSame('avatar.png', $body['original_name']);
		$this->assertSame('image/png', $body['mime_type']);
		$this->assertSame(100, $body['filesize']);
		$this->assertFalse($body['is_orphan']);
		$this->assertArrayHasKey('created_at', $body);
	}

	// ---------------------------------------------------------------------------
	// GET /files/{id}/download
	// ---------------------------------------------------------------------------

	#[Test]
	public function download_returns_404_when_file_not_found(): void
	{
		$this->storageService
			->method('retrieve')
			->willThrowException(new FileNotFoundException('not found'));

		$response = $this->controller->download('missing', Request::create('/api/v1/files/missing/download'));

		$this->assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function download_returns_401_for_private_file_when_unauthenticated(): void
	{
		$file = $this->makeStoredFile(visibility: FileVisibility::Private);
		$this->storageService->method('retrieve')->willReturn($file);

		$response = $this->controller->download('abc', Request::create('/api/v1/files/abc/download'));

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function download_returns_streamed_response_for_public_file(): void
	{
		$file   = $this->makeStoredFile(visibility: FileVisibility::Public);
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, 'file-content');
		rewind($stream);

		$this->storageService->method('retrieve')->willReturn($file);
		$this->storageService->method('readStream')->willReturn($stream);

		$response = $this->controller->download('abc', Request::create('/api/v1/files/abc/download'));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('image/png', $response->headers->get('Content-Type'));
	}

	#[Test]
	public function download_returns_streamed_response_for_private_file_when_authenticated(): void
	{
		$file   = $this->makeStoredFile(visibility: FileVisibility::Private);
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, 'file-content');
		rewind($stream);

		$this->storageService->method('retrieve')->willReturn($file);
		$this->storageService->method('readStream')->willReturn($stream);

		$request = Request::create('/api/v1/files/abc/download');
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->download('abc', $request);

		$this->assertSame(200, $response->getStatusCode());
	}

	// ---------------------------------------------------------------------------
	// DELETE /files/{id}
	// ---------------------------------------------------------------------------

	#[Test]
	public function delete_returns_401_when_unauthenticated(): void
	{
		$response = $this->controller->delete('abc', Request::create('/api/v1/files/abc', 'DELETE'));

		$this->assertSame(401, $response->getStatusCode());
	}

	#[Test]
	public function delete_returns_404_when_file_not_found(): void
	{
		$this->storageService
			->method('retrieve')
			->willThrowException(new FileNotFoundException('not found'));

		$request = Request::create('/api/v1/files/abc', 'DELETE');
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->delete('abc', $request);

		$this->assertSame(404, $response->getStatusCode());
	}

	#[Test]
	public function delete_returns_403_when_user_is_not_owner(): void
	{
		$file = $this->makeStoredFile(uploaderId: 99);
		$this->storageService->method('retrieve')->willReturn($file);

		$request = Request::create('/api/v1/files/abc', 'DELETE');
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->delete('abc', $request);

		$this->assertSame(403, $response->getStatusCode());
	}

	#[Test]
	public function delete_returns_204_when_owner_deletes_file(): void
	{
		$file = $this->makeStoredFile(uploaderId: 1);
		$this->storageService->method('retrieve')->willReturn($file);
		$this->storageService->method('delete')->willReturn(new DomainEventCollection([]));

		$request = Request::create('/api/v1/files/abc', 'DELETE');
		$request->attributes->set('_api_user', $this->makeUser());

		$response = $this->controller->delete('abc', $request);

		$this->assertSame(204, $response->getStatusCode());
	}

	private function makeStoredFile(
		FileVisibility $visibility = FileVisibility::Public,
		int $uploaderId = 1,
	): StoredFile {
		return new StoredFile(
			id:           'abc',
			assetType:    AssetType::Avatar,
			visibility:   $visibility,
			originalName: 'avatar.png',
			physicalName: 'abc',
			mimeType:     'image/png',
			filesize:     100,
			checksum:     str_repeat('0', 64),
			isOrphan:     false,
			parentId:     null,
			variantType:  null,
			uploaderId:   $uploaderId,
			forumId:      0,
			createdAt:    1000000,
			claimedAt:    null,
		);
	}

	private function makeUser(int $id = 1): User
	{
		return new User(
			id:               $id,
			type:             UserType::Normal,
			username:         'testuser',
			usernameClean:    'testuser',
			email:            'test@example.com',
			passwordHash:     '',
			colour:           '',
			defaultGroupId:   2,
			avatarUrl:        '',
			registeredAt:     new \DateTimeImmutable('2024-01-01'),
			lastmark:         new \DateTimeImmutable('2024-01-01'),
			posts:            0,
			lastPostTime:     null,
			isNew:            false,
			rank:             0,
			registrationIp:   '127.0.0.1',
			loginAttempts:    0,
			inactiveReason:   null,
			formSalt:         '',
			activationKey:    '',
		);
	}
}
