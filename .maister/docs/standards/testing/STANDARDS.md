# Testing Standards

PHPUnit 10+ conventions and patterns for the phpBB Vibed test suite.

## Framework & Version

- **PHPUnit 10+** (target: `^10.0`)
- Tests live in `tests/` mirroring the source structure (e.g., `phpbb/auth/` → `tests/auth/`)
- Run the suite: `vendor/bin/phpunit -c phpunit.xml`

## Test Naming

### Files & Classes
- Test file: `<SubjectName>Test.php` (PascalCase, e.g., `SessionTest.php`, `AuthProviderTest.php`)
- Test class namespace:
  - Older modules: `phpbb\tests\<module>\` (lowercase `tests`) — retained for compatibility
  - **Newer modules (preferred)**: `phpbb\Tests\<module>\` (PascalCase `Tests`)
  - File always lives under `tests/phpbb/<module>/` — PSR-4 autoload maps both forms
  - **For all new test files use `phpbb\Tests\<module>\`**

### Method Names
- Use descriptive `camelCase` starting with `test`:
  - `testGetUserReturnsFalseForUnknownId()`
  - `testLoginThrowsOnInvalidCredentials()`
  - `testPostCreatedWithCorrectTimestamp()`
- Or use the `#[Test]` attribute with any descriptive method name:
  ```php
  #[Test]
  public function getUserReturnsFalseForUnknownId(): void { ... }
  ```
- Never name tests `test1()`, `testA()`, or other meaningless identifiers

## PHP 8 Attributes (PHPUnit 10+)

Use PHP 8 attributes instead of annotations for all test metadata:

```php
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[Test]
public function loginReturnsTokenOnSuccess(): void { ... }

#[Test]
#[DataProvider('provideInvalidUsernames')]
public function validateUsernameRejectsInvalid(string $username): void { ... }

#[Before]
public function initDatabase(): void { ... }

#[Group('slow')]
#[Test]
public function heavyIntegrationTest(): void { ... }
```

> **No annotations**: Do not use `/** @test */`, `/** @dataProvider */`, `/** @group */`, `/** @requires */`. These are deprecated in PHPUnit 10.

## Test Structure (AAA)

Follow Arrange–Act–Assert in every test:

```php
#[Test]
public function calculatePostCountIncludesSoftDeleted(): void
{
    // Arrange
    $user = $this->createMockedUser(['post_count' => 5]);

    // Act
    $result = $this->counter->calculate($user, include_deleted: true);

    // Assert
    $this->assertSame(5, $result);
}
```

## `setUp()` and `tearDown()`

Always declare explicit `void` return type:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->subject = new AuthProvider($this->createMock(DriverInterface::class));
}

protected function tearDown(): void
{
    // clean up open resources
    parent::tearDown();
}
```

## Data Providers

Providers must be `public static` methods returning an iterable. Use named keys for descriptive failure messages:

```php
public static function provideInvalidUsernames(): array
{
    return [
        'empty string'   => [''],
        'too long'       => [str_repeat('a', 256)],
        'special chars'  => ['user<script>'],
    ];
}

#[Test]
#[DataProvider('provideInvalidUsernames')]
public function validateUsernameRejectsInvalid(string $username): void
{
    $this->assertFalse($this->validator->validate($username));
}
```

## Integration Tests — SQLite In-Memory

Repository / integration tests extend `IntegrationTestCase` (at `tests/phpbb/Integration/IntegrationTestCase.php`), which spins up an in-memory SQLite database via Doctrine DBAL. There are no XML/fixture files.

```php
namespace phpbb\Tests\messaging\Integration;

use phpbb\Tests\Integration\IntegrationTestCase;
use phpbb\messaging\Repository\DbalConversationRepository;

final class DbalConversationRepositoryTest extends IntegrationTestCase
{
    private DbalConversationRepository $repository;

    protected function setUpSchema(): void
    {
        $this->connection->executeStatement('
            CREATE TABLE phpbb_conversations (
                conversation_id INT PRIMARY KEY,
                participant_hash VARCHAR(40) NOT NULL,
                created_by INT NOT NULL,
                created_at INT NOT NULL,
                last_message_id INT,
                message_count INT NOT NULL DEFAULT 0
            )
        ');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DbalConversationRepository($this->connection);
    }

    public function testFindsConversationById(): void
    {
        // Arrange — insert directly via $this->connection->executeStatement()
        // Act — call repository method
        // Assert — PHPUnit assertions
    }
}
```

Key points:
- `IntegrationTestCase` is at `tests/phpbb/Integration/IntegrationTestCase.php`
- `$this->connection` is a Doctrine DBAL `Connection` (SQLite in-memory)
- `setUpSchema()` is called by `parent::setUp()` — must create all required tables
- No fixture files, no XML datasets — insert test data inline via `$this->connection->executeStatement()`
- Namespace: `phpbb\Tests\<module>\Integration\`
- Each test is isolated — schema is recreated per test run

## Mocking

- Use PHPUnit's built-in `createMock()` / `getMockBuilder()` — no third-party mock libraries
- Mock at the interface boundary, not the concrete class where possible
- Verify interactions with `expects($this->once())` / `expects($this->exactly(n))` when call count matters
- Avoid over-mocking: if setup is complex, consider an integration test with the real class

### Intersection type for mock properties

Declare mock properties using the `InterfaceName&MockObject` intersection type. This gives IDE autocompletion for both the interface's own methods and PHPUnit mock methods (`expects`, `willReturn`, etc.):

```php
use PHPUnit\Framework\MockObject\MockObject;
use phpbb\messaging\Repository\ConversationRepositoryInterface;

final class ConversationServiceTest extends TestCase
{
    private ConversationRepositoryInterface&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ConversationRepositoryInterface::class);
    }

    public function testCreate(): void
    {
        $this->repo->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(Conversation::class));
        // ...
    }
}
```

Simple inline mocks (not reused across methods) may be declared without the intersection type:

```php
$db = $this->createMock(DriverInterface::class);
$db->expects($this->once())
   ->method('sql_query')
   ->willReturn($mockResult);
```

## Test Isolation

- No shared mutable state between tests (no `static` properties modified in tests)
- Each test must pass in isolation and in any order
- `setUp()` initializes all state; `tearDown()` cleans up resources (open files, DB connections)
- Do not rely on test execution order

## Assertions

- Use the most specific assertion available:
  - `assertSame()` over `assertEquals()` (strict type + value check)
  - `assertCount()` over `assertEquals(n, count(...))`
  - `assertInstanceOf()` to verify return types
- One logical assertion per test (multiple `assert*` calls are fine if they verify the same outcome)
- For expected exceptions use `$this->expectException()` — **never** use `@expectedException` annotation

```php
#[Test]
public function loginThrowsForUnknownUser(): void
{
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('User not found');
    $this->auth->login('nobody', 'pass');
}
```

## Coverage & Quality

- Aim for ≥ 80% line coverage on new `phpbb\` OOP code
- Mark known-failing or environment-dependent tests with `#[Group('slow')]` or `#[RequiresPhpExtension('pdo')]`
- Use `#[DataProvider]` for parameterized tests — never use loops inside a test method

## Test Toolchain

### Version Requirements
- **PHPUnit**: `^10.0` (unit & integration, PHP)
- **Playwright** (TypeScript): E2E browser tests in `tests/e2e/`, config at `tests/e2e/playwright.config.ts`
- **Code style**: `squizlabs/php_codesniffer: ~3.4`

Run PHP tests: `vendor/bin/phpunit -c phpunit.xml`  
Run E2E tests: `composer test:e2e` (runs Playwright via the `tests/e2e/` directory)

### Test Layers
| Layer | Tool | Location | Use for |
|---|---|---|---|
| Unit | PHPUnit 10 (MockObject built-in) | `tests/phpbb/<module>/` | Single class/method, fully mocked dependencies |
| Integration (DB) | PHPUnit 10 + `IntegrationTestCase` (SQLite in-memory) | `tests/phpbb/<module>/Integration/` | Repository / DBAL layer tests |
| E2E | Playwright (TypeScript) | `tests/e2e/` | Full browser automation for API and UI-critical flows |

