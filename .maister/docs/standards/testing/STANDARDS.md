# Testing Standards

PHPUnit conventions and patterns for the phpBB test suite.

## Framework & Version

- **PHPUnit 7+** (compatible with PHPUnit 9 for newer environments)
- Tests live in `tests/` mirroring the source structure (e.g., `phpbb/auth/` → `tests/auth/`)
- Run the suite: `vendor/bin/phpunit -c phpunit.xml`

## Test Naming

### Files & Classes
- Test file: `<subject>_test.php` (e.g., `session_test.php`)
- Test class: `phpbb_<component>_test` or `phpbb\tests\<component>\<subject>_test`

### Method Names
- Pattern: `test_<method>_<condition>` (snake_case)
- Be descriptive about the scenario being tested:

```php
public function test_get_user_returns_false_for_unknown_id() { ... }
public function test_login_throws_on_invalid_credentials()   { ... }
public function test_post_created_with_correct_timestamp()   { ... }
```

- Never name tests `test1()`, `testA()`, or other meaningless identifiers

## Test Structure (AAA)

Follow Arrange–Act–Assert in every test:

```php
public function test_calculate_post_count_includes_soft_deleted()
{
    // Arrange
    $user = $this->createMockedUser(['post_count' => 5]);

    // Act
    $result = $this->counter->calculate($user, true);

    // Assert
    $this->assertSame(5, $result);
}
```

## Database Tests

- Extend `phpbb_database_test_case` for tests that require DB interaction
- Provide fixtures via `getDataSet()` returning a `PHPUnit_Extensions_Database_DataSet_XmlDataSet`
- Use `tests/dbal/fixtures/` for XML fixture files
- Reset auto-increment and truncate tables in `setUp()` via `$this->db->sql_query('TRUNCATE ...')`
- Never share DB state between tests — each test must be fully independent

```php
class phpbb_dbal_select_test extends phpbb_database_test_case
{
    protected function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/fixtures/select.xml');
    }
}
```

## Mocking

- Use PHPUnit's built-in `createMock()` / `getMockBuilder()` — no third-party mock libraries
- Mock at the interface boundary, not the concrete class where possible
- Verify interactions with `expects($this->once())` / `expects($this->exactly(n))` when the call count matters
- Avoid over-mocking: if setup is complex, consider an integration test with the real class

```php
$db = $this->createMock(\phpbb\db\driver\driver_interface::class);
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
  - Prefer `assertSame()` over `assertEquals()` (strict type check)
  - Prefer `assertCount()` over `assertEquals(n, count(...))`
  - Use `assertInstanceOf()` to verify return types
- One logical assertion per test (multiple `assert*` calls are fine if they verify the same outcome)

## Coverage & Quality

- Aim for ≥ 80% line coverage on new phpbb\ OOP code
- Mark known-failing or environment-dependent tests with `@group slow` or `@requires extension ...`
- Use `@dataProvider` for parameterized tests rather than loops inside a test method

```php
/** @dataProvider provide_invalid_usernames */
public function test_validate_username_rejects_invalid(string $username): void
{
    $this->assertFalse($this->validator->validate($username));
}

public static function provide_invalid_usernames(): array
{
    return [
        'empty string'      => [''],
        'too long'          => [str_repeat('a', 256)],
        'special chars'     => ['user<script>'],
    ];
}
```

## Test Toolchain

### Version Requirements
- **PHPUnit**: `^7.0` (configured in `composer.json` require-dev)
- **phpunit/dbunit**: `~4.0` — for database integration tests extending `phpbb_database_test_case`
- **fabpot/goutte**: `~3.2` — functional/integration HTTP tests
- **php-webdriver/webdriver**: `~1.8` — E2E browser tests via Selenium WebDriver
- **Code style**: `squizlabs/php_codesniffer: ~3.4`

Run all tests: `vendor/bin/phpunit -c phpunit.xml`

### Test Layers
| Layer | Tool | Use for |
|---|---|---|
| Unit | PHPUnit 7 | Single class/method, fully mocked dependencies |
| DB Integration | PHPUnit + DbUnit 4 | Tests requiring real database operations |
| Functional (HTTP) | Goutte | Full HTTP request/response testing without a real browser |
| E2E | Selenium WebDriver | Full browser automation for UI-critical flows |
```
