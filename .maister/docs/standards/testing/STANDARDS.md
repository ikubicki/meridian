# Testing Standards

PHPUnit 10+ conventions and patterns for the phpBB Vibed test suite.

## Framework & Version

- **PHPUnit 10+** (target: `^10.0`)
- Tests live in `tests/` mirroring the source structure (e.g., `phpbb/auth/` → `tests/auth/`)
- Run the suite: `vendor/bin/phpunit -c phpunit.xml`

## Test Naming

### Files & Classes
- Test file: `<SubjectName>Test.php` (PascalCase, e.g., `SessionTest.php`, `AuthProviderTest.php`)
- Test class: `phpbb\tests\<component>\<Subject>Test` (matches file name)

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

## Database Tests

- Extend `phpbb_database_test_case` for tests requiring DB interaction
- Provide fixtures via `getDataSet()` returning an `XmlDataSet`
- Use `tests/dbal/fixtures/` for XML fixture files
- Reset auto-increment and truncate tables in `setUp()` via `$this->db->sql_query('TRUNCATE ...')`
- Never share DB state between tests — each test must be fully independent

```php
class SelectTest extends phpbb_database_test_case
{
    protected function getDataSet(): \PHPUnit\DbUnit\DataSet\IDataSet
    {
        return $this->createXMLDataSet(__DIR__ . '/fixtures/select.xml');
    }
}
```

## Mocking

- Use PHPUnit's built-in `createMock()` / `getMockBuilder()` — no third-party mock libraries
- Mock at the interface boundary, not the concrete class where possible
- Verify interactions with `expects($this->once())` / `expects($this->exactly(n))` when call count matters
- Avoid over-mocking: if setup is complex, consider an integration test with the real class

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
- **PHPUnit**: `^10.0`
- **phpunit/dbunit**: replaced by `phpunit/db-unit` or direct DB setup in `setUp()`
- **fabpot/goutte**: `~3.2` — functional/integration HTTP tests
- **php-webdriver/webdriver**: `~1.8` — E2E browser tests via Selenium WebDriver
- **Code style**: `squizlabs/php_codesniffer: ~3.4`

Run all tests: `vendor/bin/phpunit -c phpunit.xml`

### Test Layers
| Layer | Tool | Use for |
|---|---|---|
| Unit | PHPUnit 10 | Single class/method, fully mocked dependencies |
| DB Integration | PHPUnit + manual setUp | Tests requiring real database operations |
| Functional (HTTP) | Goutte | Full HTTP request/response testing without a real browser |
| E2E | Selenium WebDriver | Full browser automation for UI-critical flows |

