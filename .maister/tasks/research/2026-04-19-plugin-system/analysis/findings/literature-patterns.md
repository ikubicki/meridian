# Literature Patterns: Plugin/Extension Architecture in PHP Frameworks

## 1. WordPress — Hooks, Options API, Custom Tables

### Hook System (Actions + Filters)

WordPress plugin architecture is built on two hook types:

**Actions** — fire at specific points in execution; callbacks perform side effects (echo output, insert DB rows) and return nothing:
```php
add_action('init', 'pluginprefix_setup_post_type');
do_action('save_post', $post_id, $post);
```

**Filters** — modify data in a pipeline; each callback receives a value, transforms it, returns it:
```php
add_filter('the_content', 'pluginprefix_modify_content');
$title = apply_filters('the_title', $raw_title, $post_id);
```

**Key design properties:**
- Global function registry (priority-ordered linked list per hook name)
- Hooks are string-identified (no type safety)
- Any code can add/remove hooks at any time (no compile-time guarantees)
- Filters are a pipeline pattern; actions are observer pattern
- Supports priority ordering (default 10, lower = earlier)

**Relation to modern patterns:**
- Actions → PSR-14 EventDispatcher (observer/listener pattern)
- Filters → Decorator/Pipeline pattern (middleware chain)
- Hook strings → Event class names in modern systems

### Plugin Header Metadata

Metadata is stored as PHP comments in the main plugin file:
```php
/**
 * Plugin Name: My Plugin
 * Plugin URI: https://example.com
 * Description: Short description
 * Version: 1.0.0
 * Author: Name
 * License: GPL-2.0+
 * Text Domain: my-plugin
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */
```

**Design note:** Simple but fragile — parsed via regex, no schema validation, no IDE autocompletion. Modern systems use `composer.json` extra section or dedicated manifest files.

### Activation / Deactivation / Uninstall Hooks

```php
register_activation_hook(__FILE__, 'pluginprefix_activate');
register_deactivation_hook(__FILE__, 'pluginprefix_deactivate');
// Uninstall: separate uninstall.php or register_uninstall_hook()
```

**Lifecycle pattern:**
- **Activate**: Create tables, set default options, flush rewrite rules
- **Deactivate**: Remove temporary data (caches, temp files), NOT permanent data
- **Uninstall**: Remove all plugin data permanently (options, tables, files)

**Key insight:** WordPress separates "disable" (deactivate) from "remove" (uninstall), allowing users to temporarily disable without data loss.

### Options API (Key-Value Storage)

```php
add_option('my_plugin_settings', ['key' => 'value']);
update_option('my_plugin_settings', $new_value);
get_option('my_plugin_settings', $default);
delete_option('my_plugin_settings');
```

**Properties:**
- Simple key-value store in `wp_options` table
- Supports serialized arrays/objects (auto-serialize/deserialize)
- Optional autoload flag (loaded on every page or on-demand)
- No schema validation at storage level
- Plugin prefix convention prevents collisions

### Custom Post Types / Custom Tables

**Custom Post Types** — extend existing polymorphic `wp_posts` table:
```php
register_post_type('book', ['public' => true, 'label' => 'Books']);
```
- Leverages existing infrastructure (search, REST API, admin UI)
- Flexible but can lead to "post type abuse" for non-content data

**Custom Tables** — plugin creates its own DB tables:
```php
// In activation hook:
$wpdb->query("CREATE TABLE {$wpdb->prefix}my_table (...)");
```
- Full schema control, proper indexing
- Must handle own CRUD, no automatic admin UI
- Migrations managed manually via `dbDelta()` function

---

## 2. Symfony Bundles — DI Integration, Tagged Services, Config Tree

### Bundle Class + DI Extension Pattern

**Modern approach (AbstractBundle):**
```php
namespace Acme\SocialBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

class AcmeSocialBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('twitter')
                    ->children()
                        ->integerNode('client_id')->end()
                        ->scalarNode('client_secret')->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
        $container->services()
            ->get('acme_social.twitter_client')
            ->arg(0, $config['twitter']['client_id']);
    }
}
```

**Key design properties:**
- Bundle is a first-class DI citizen — it extends the container at compile time
- Configuration is validated and merged BEFORE services are loaded
- The `$config` parameter is already processed (merged from multiple config files)
- Environment-aware registration (dev/test/prod bundles)

**Traditional approach (Extension class):**
```php
// src/DependencyInjection/AcmeSocialExtension.php
class AcmeSocialExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        // Load services, set parameters
    }
}
```

### Compiler Passes for DI Manipulation

Compiler passes allow bundles to modify the container after all extensions have loaded:

```php
class MyBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MyCompilerPass());
    }
}

class MyCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $definition = $container->getDefinition('some_service');
        $taggedServices = $container->findTaggedServiceIds('my_bundle.handler');
        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('addHandler', [new Reference($id)]);
        }
    }
}
```

**Key design properties:**
- Two-phase boot: extensions load definitions → compiler passes wire them together
- Enables "collect and inject" pattern (find all tagged services, inject into registry)
- Deterministic ordering via priority
- Compile-time resolution — no runtime overhead

### Tagged Services (Auto-Discovery)

```yaml
services:
    App\Handler\:
        resource: '../src/Handler/'
        tags: ['app.message_handler']
```

Or via PHP attributes (Symfony 6.1+):
```php
#[AutoconfigureTag('app.message_handler')]
class MyHandler implements HandlerInterface {}
```

**Pattern:** Services self-declare capabilities via tags; compiler passes collect them into registries/dispatchers. This is the foundation of Symfony's extensibility.

### Configuration Tree (Semantic Config)

```php
$treeBuilder = new TreeBuilder('acme_social');
$treeBuilder->getRootNode()
    ->children()
        ->arrayNode('twitter')
            ->children()
                ->integerNode('client_id')->end()
                ->scalarNode('client_secret')->end()
            ->end()
        ->end()
    ->end();
```

**Properties:**
- Schema-first: define structure before loading values
- Built-in validation (type checking, required fields, allowed values)
- Multi-source merging (dev.yaml overrides base.yaml)
- Normalization (singular/plural, shortcuts)
- Dumpable (`config:dump-reference` shows full schema with defaults)

### Doctrine Migrations Integration

Bundles ship migrations in a standard directory; DoctrineMigrationsBundle discovers them. Migration versioning uses timestamps. Bundles can prepend configuration to modify Doctrine mapping.

---

## 3. Laravel Packages — Service Providers, Package Discovery, Migrations

### Service Providers (register + boot)

```php
class CourierServiceProvider extends ServiceProvider
{
    // Phase 1: Register bindings (no other services available yet)
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/courier.php', 'courier');
        $this->app->singleton(CourierClient::class, function ($app) {
            return new CourierClient($app['config']['courier.api_key']);
        });
    }

    // Phase 2: Boot (all providers registered, full container available)
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/courier.php' => config_path('courier.php'),
        ]);
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ]);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'courier');
    }
}
```

**Key design properties:**
- Two-phase lifecycle: `register()` (bind) → `boot()` (configure)
- `register()` must NOT use other services (they may not be registered yet)
- `boot()` has full container access — order-independent
- Providers are the single integration point (DI, config, routes, views, migrations)

### Package Discovery (Composer Extra)

```json
{
    "extra": {
        "laravel": {
            "providers": ["Barryvdh\\Debugbar\\ServiceProvider"],
            "aliases": {"Debugbar": "Barryvdh\\Debugbar\\Facade"}
        }
    }
}
```

**Pattern:** Zero-config installation — `composer require` automatically registers the provider. Users can opt-out via `dont-discover`. This is the gold standard for DX.

### Publishing Config / Migrations

```php
// Config publishing
$this->publishes([
    __DIR__.'/../config/package.php' => config_path('package.php')
], 'courier-config');

// Migration publishing
$this->publishesMigrations([
    __DIR__.'/../database/migrations' => database_path('migrations')
], 'courier-migrations');
```

**Pattern:** "Publish to customize" — package provides defaults, user can copy/override. Tagged groups allow selective publishing (`php artisan vendor:publish --tag=courier-config`).

### Facades and Contracts

- **Contracts** (interfaces): define the API boundary
- **Facades**: static-proxy access to container services (convenience, testable via `shouldReceive`)
- Packages code against interfaces, bind implementations in providers

### Event Discovery

Laravel auto-discovers event listeners by convention (class name → method name). Events are simple value objects. Listeners can be queued (async).

---

## 4. Shopware 6 — Custom Fields, Custom Entities, Lifecycle Methods

### Plugin Base Class with Lifecycle Methods

```php
namespace Swag\BasicExample;

use Shopware\Core\Framework\Plugin;

class SwagBasicExample extends Plugin
{
    public function install(InstallContext $installContext): void { }
    public function activate(ActivateContext $activateContext): void { }
    public function deactivate(DeactivateContext $deactivateContext): void { }
    public function update(UpdateContext $updateContext): void { }
    public function uninstall(UninstallContext $uninstallContext): void { }
    public function postInstall(InstallContext $installContext): void { }
    public function postUpdate(UpdateContext $updateContext): void { }
}
```

**Inheritance chain:**
```
Plugin → Shopware\Core\Framework\Bundle → Symfony\Component\HttpKernel\Bundle\Bundle
```

**Key design properties:**
- Richest lifecycle of all frameworks (install, activate, deactivate, update, uninstall + post-hooks)
- Context objects provide version info, migration control, access to DI container
- `uninstall()` receives `keepUserData()` flag — user chooses whether to purge data
- Separates "install" (create payment methods etc.) from "activate" (enable them)
- Lifecycle has access to `$this->container` (full DI)

### Entity Extensions (Custom Fields System)

**Custom Fields** (JSON column on existing tables):
```php
// Entity definition
protected function defineFields(): FieldCollection
{
    return new FieldCollection([
        (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
        (new StringField('name', 'name')),
        new CustomFields() // JSON column
    ]);
}

// Writing data
$repository->upsert([[
    'id' => $id,
    'customFields' => ['swag_example_size' => 15]
]], $context);
```

**Design characteristics:**
- No schema migration needed to add fields — just write JSON
- Optional schema definition for Admin UI and validation
- Translatable custom fields via `TranslatedField('customFields')`
- Custom field sets group related fields
- Searchable flag per field (index optimization)
- Cleanup on uninstall: `JSON_REMOVE` in batches

**Relevance to phpBB:** This is essentially "profile fields on steroids" — applicable to any entity. Very relevant for extensibility without schema changes.

### Custom Entities (Plugin-Defined DB Tables)

Plugins define full DAL entities with dedicated tables:
```php
class ExampleDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'swag_example';

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('name', 'name')),
            new CustomFields()
        ]);
    }
}
```

Migrations create the table; the DAL provides CRUD, search, versioning automatically.

### Flow Builder (Event-Driven Automation)

- Events trigger flows (business rules)
- Flows consist of conditions + actions
- Plugins register custom triggers, conditions, and actions
- Non-developer users can compose behaviors in admin UI

### Plugin Manifest / Composer.json Hybrid

```json
{
    "type": "shopware-platform-plugin",
    "extra": {
        "shopware-plugin-class": "Swag\\BasicExample\\SwagBasicExample",
        "label": {"en-GB": "...", "de-DE": "..."},
        "description": {"en-GB": "...", "de-DE": "..."}
    },
    "require": {"shopware/core": "~6.7.0"}
}
```

- Plugin identity lives in `composer.json` (not file headers like WordPress)
- Type field (`shopware-platform-plugin`) enables auto-discovery
- Multilingual metadata in `extra` section

---

## 5. Pattern Comparison Matrix

| Aspect | WordPress | Symfony Bundles | Laravel Packages | Shopware 6 |
|--------|-----------|----------------|-----------------|-------------|
| **Lifecycle Stages** | activate, deactivate, uninstall | none (boot only) | register, boot | install, activate, deactivate, update, uninstall (+ post-hooks) |
| **DI Integration** | None (global functions) | Full (extensions, compiler passes, tagged services) | Service providers (register/boot) | Full Symfony DI (inherits bundles) |
| **Configuration** | Options API (key-value) | Config tree (typed, validated, merged) | Config files (publish to override) | config.xml + Symfony config |
| **Event System** | Actions + Filters (string names) | EventDispatcher (typed events) | Events + Listeners (class-based) | Symfony events + Flow Builder |
| **Schema Management** | Manual `dbDelta()` | Doctrine migrations | Publishable migrations (timestamped) | Plugin migrations (auto-detected) |
| **Metadata** | PHP file header comments | composer.json + bundle class | composer.json extra section | composer.json extra + labels |
| **Custom Storage** | wp_options + custom tables | Doctrine entities | Eloquent models | DAL entities + custom fields JSON |
| **Auto-Discovery** | File scan in plugins dir | `config/bundles.php` | Composer extra auto-registration | `plugin:refresh` CLI scan |
| **Data Extensibility** | Meta tables (postmeta, usermeta) | Entity listeners / Doctrine events | Model events, traits | Custom fields (JSON) + entity extensions |
| **Compile-Time Safety** | None | Full (compiled container) | Partial (config cache) | Full (compiled container) |

---

## 6. Best-Fit Patterns for phpBB Architecture

### Recommended: Hybrid Approach

Given phpBB's constraints (Symfony DI container, legacy procedural code, PHP 8.2+ target):

#### 6.1 Lifecycle — Adopt Shopware Model (Best in Class)

**Why:** Shopware's 5-stage lifecycle (install → activate → deactivate → update → uninstall) with context objects is the most complete and handles real-world scenarios:
- Install creates data structures
- Activate enables functionality (separate from install — allows "installed but disabled")
- Update handles version-specific migrations
- Uninstall with `keepUserData` flag

**Adaptation for phpBB:**
```php
namespace phpbb\plugin;

abstract class AbstractPlugin
{
    abstract public function install(InstallContext $context): void;
    abstract public function activate(ActivateContext $context): void;
    abstract public function deactivate(DeactivateContext $context): void;
    abstract public function update(UpdateContext $context): void;
    abstract public function uninstall(UninstallContext $context): void;
}
```

#### 6.2 DI Integration — Adopt Symfony Bundle Pattern

**Why:** phpBB already uses Symfony DI. Bundles provide the cleanest integration:
- Service definitions in `config/services.yaml` or PHP
- Tagged services for extension points (notification types, auth providers, etc.)
- Compiler passes for "collect all implementations" pattern
- Semantic configuration with validation

**Adaptation:**
```php
namespace phpbb\plugin\acme_forum_tools;

class AcmeForumToolsPlugin extends AbstractPlugin
{
    // Symfony-style: loadExtension registers services
    public function loadExtension(array $config, ContainerConfigurator $container): void
    {
        $container->import('./config/services.php');
    }

    // Semantic config
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enable_feature_x')->defaultTrue()->end()
            ->end();
    }
}
```

#### 6.3 Events — PSR-14 + Filter Pipeline

**Why:** Combines WordPress filter elegance with type safety:
- Actions → PSR-14 EventDispatcher (already in phpBB's direction)
- Filters → Middleware-style pipeline for data transformation

```php
// Type-safe events (Symfony/PSR-14 style)
class PostBeforeSaveEvent {
    public function __construct(
        public readonly int $forumId,
        public string $content,  // mutable = filter
        public readonly User $author,
    ) {}
}
```

#### 6.4 Data Extensibility — Adopt Shopware Custom Fields

**Why:** JSON columns on existing entities are the best balance of:
- No schema migrations for simple extensions
- Queryable (MySQL JSON functions)
- Typed validation optional but available
- Translatable
- Clean removal on uninstall

**For phpBB:** Add `custom_fields JSON DEFAULT NULL` to key tables (forums, topics, users, posts). Plugins store typed data without schema changes.

#### 6.5 Package Discovery — Adopt Laravel Pattern

**Why:** Zero-config installation via `composer.json` extra:
```json
{
    "type": "phpbb-plugin",
    "extra": {
        "phpbb-plugin-class": "Acme\\ForumTools\\AcmeForumToolsPlugin",
        "label": "Forum Tools"
    }
}
```

Combined with `plugin:refresh` CLI (Shopware-style) for non-Composer installs.

#### 6.6 Schema Management — Adopt Shopware/Laravel Hybrid

- Migrations live in plugin's `migrations/` directory
- Auto-discovered and versioned (timestamp-based)
- Executed during `install()` and `update()` lifecycle stages
- `keepUserData` flag on uninstall controls table dropping
- phpBB's existing `\phpbb\db\migration\migration` system is already close to this

---

## 7. Anti-Patterns to Avoid

### 7.1 WordPress Global Function Registry
- **Problem:** String-based hook names, no type safety, any priority can be inserted, impossible to know all listeners at compile time
- **Instead:** Use typed events and compile-time container wiring

### 7.2 WordPress Options API Without Schema
- **Problem:** Serialized PHP objects in DB, no validation, autoload bloat
- **Instead:** Typed configuration (Symfony config tree) + JSON columns for runtime data

### 7.3 Unrestricted Custom Tables (WordPress Pattern)
- **Problem:** Each plugin creates own tables with own conventions, no standard CRUD, no admin UI
- **Instead:** Entity definition system (Shopware DAL) or standard base entity classes

### 7.4 Runtime Service Discovery
- **Problem:** Scanning filesystem or DB for plugins on every request
- **Instead:** Compile-time container (Symfony) — discover once, cache the wiring

### 7.5 Monolithic Service Provider (Laravel Anti-Pattern)
- **Problem:** Single provider doing registration + boot + config + routes + views = god class
- **Instead:** Separate concerns: plugin class for lifecycle, separate DI extension for services, separate config definition

### 7.6 Mutable Global State
- **Problem:** WordPress `global $wp_filter` — any code can modify behavior at any time
- **Instead:** Immutable compiled container + event dispatching with clear ownership

### 7.7 No Lifecycle Separation
- **Problem:** Symfony bundles have no install/uninstall — just "loaded or not"
- **Instead:** Explicit lifecycle stages (Shopware model) allowing reversible operations

### 7.8 Over-Engineering Entity Extensions
- **Problem:** Full Doctrine entity extension for simple key-value data
- **Instead:** Use custom fields (JSON) for simple/scalar data, entity extensions only for associations and complex queries

### 7.9 Tight Coupling to Framework Internals
- **Problem:** Plugins using `$container->get()` directly, accessing private services
- **Instead:** Define clear extension points (interfaces, tagged services), never expose internal services

### 7.10 No Data Cleanup Strategy
- **Problem:** Plugins leave orphaned data in shared tables after uninstall
- **Instead:** Custom fields with prefixed keys + batched cleanup (Shopware JSON_REMOVE pattern)

---

## Sources

| Source | URL | Accessed |
|--------|-----|----------|
| WordPress Plugin Hooks | https://developer.wordpress.org/plugins/hooks/ | 2026-04-19 |
| WordPress Activation/Deactivation Hooks | https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/ | 2026-04-19 |
| Symfony Bundle System | https://symfony.com/doc/current/bundles.html | 2026-04-19 |
| Symfony Bundle Configuration | https://symfony.com/doc/current/bundles/configuration.html | 2026-04-19 |
| Symfony Bundle Extension | https://symfony.com/doc/current/bundles/extension.html | 2026-04-19 |
| Laravel Package Development | https://laravel.com/docs/11.x/packages | 2026-04-19 |
| Shopware 6 Plugin Base Guide | https://developer.shopware.com/docs/guides/plugins/plugins/plugin-base-guide.html | 2026-04-19 |
| Shopware 6 Plugin Lifecycle | https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/plugin-lifecycle.html | 2026-04-19 |
| Shopware 6 Custom Fields | https://developer.shopware.com/docs/guides/plugins/plugins/framework/custom-field/add-custom-field.html | 2026-04-19 |

**Confidence:** High (90-95%) — based on official documentation from each framework.
