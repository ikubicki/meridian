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
use phpbb\storage\Enum\AssetType;
use phpbb\storage\Event\FileStoredEvent;
use phpbb\storage\Exception\QuotaExceededException;
use phpbb\storage\Exception\UploadValidationException;
use phpbb\user\Entity\User;
use phpbb\user\Enum\UserType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class FilesControllerTest extends TestCase
{
	private StorageServiceInterface $storageService;
	private EventDispatcherInterface $dispatcher;
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

	private function makeUser(): User
	{
		return new User(
			id:               1,
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
