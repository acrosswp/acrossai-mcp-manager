# wpb-access-control

Extensible per-resource access control library for WordPress plugins.

Answers one question: **"Does this user have access to this resource?"**

The library owns its own database table (managed by **BerlinDB**), ships a
WordPress role provider out of the box, and exposes a single method your
plugin calls wherever it needs to gate access — REST API, form submission,
WP-CLI, anywhere.

---

## Requirements

- PHP 7.4+
- WordPress 5.9+
- `automattic/jetpack-autoloader` **^2.0** (mandatory — see below)
- `berlindb/core` **^2.0** (DB layer)

---

## Installation

```bash
composer require wpboilerplate/wpb-access-control
```

Your `composer.json` must include Jetpack Autoloader:

```json
{
    "require": {
        "automattic/jetpack-autoloader": "^2.0",
        "berlindb/core": "^2.0",
        "wpboilerplate/wpb-access-control": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/WPBoilerplate/wpb-access-control.git"
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "automattic/jetpack-autoloader": true
        }
    }
}
```

> **Why Jetpack Autoloader is mandatory**
>
> If two plugins both install this library at different versions, PHP will
> throw a fatal "class already declared" error. Jetpack Autoloader scans
> every installed plugin, finds all copies of the library, and loads only
> the newest version.

---

## Setup

### Boot the manager

Instantiate `AccessControlManager` early (e.g. in `plugins_loaded`). Always
pass a **plugin-specific filter tag** to prevent your providers from bleeding
into other plugins that also use this library.

```php
use WPBoilerplate\AccessControl\AccessControlManager;

add_action( 'plugins_loaded', function () {
    $manager = new AccessControlManager( 'my_plugin_access_control_providers' );
} );
```

That's it. `AccessControlManager` owns a `RuleQuery` internally, which
registers `RuleTable` on first instantiation. BerlinDB creates or upgrades
the `{prefix}wpb_access_control` table automatically on `admin_init`.

---

## Checking access

```php
$allowed = $manager->user_has_access(
    get_current_user_id(),   // int  — 0 = unauthenticated
    'my-namespace',          // string — your plugin's namespace
    'my-resource'            // string — the specific resource
);

if ( ! $allowed ) {
    wp_die( 'Access denied.', 403 );
}
```

### Access hierarchy

| Step | Condition | Result |
|------|-----------|--------|
| 1 | `access_control_key` empty or `'everyone'` | **Allow** |
| 2 | User has `manage_options` (administrator) | **Always allow** |
| 3 | User ID = 0 (unauthenticated) | **Deny** |
| 4 | No provider registered for the configured key | **Deny** |
| 5 | `provider->user_has_access()` | Allow or **Deny** |

---

## Reading and writing rules directly

Use `RuleQuery` when you need to read or write rules outside of the built-in
admin UI flow.

```php
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

$query = new RuleQuery();

// Read the current rule.
$rule = $query->get_rule( 'my-namespace', 'my-resource' );
// → ['key' => 'wp_role', 'value' => ['editor', 'author']]
// → ['key' => '', 'value' => []]   when no rule is set

// Save a rule (sanitized internally).
$query->set_rule( 'my-namespace', 'my-resource', 'wp_role', ['editor', 'author'] );

// Allow everyone.
$query->set_rule( 'my-namespace', 'my-resource', 'everyone', [] );

// Clear a rule (resource reverts to "no restriction configured").
$query->clear_rule( 'my-namespace', 'my-resource' );

// Plugin uninstall — remove all rows for your namespace.
$query->purge_namespace( 'my-namespace' );
```

You can also access the query instance from the manager:

```php
$rule = $manager->get_query()->get_rule( 'my-namespace', 'my-resource' );
```

---

## Admin UI

The library ships a ready-to-use admin panel — type dropdown, role
checkboxes, and user search-as-you-type with multi-select tags. It handles
search and saving via AJAX.

### 1. Bootstrap the UI once

```php
use WPBoilerplate\AccessControl\AccessControlManager;
use WPBoilerplate\AccessControl\Admin\AccessControlUI;

$manager = new AccessControlManager( 'my_plugin_access_control_providers' );
$ui      = new AccessControlUI( $manager );
```

If you cannot create the UI instance until later, call the shared bootstrap
explicitly so the AJAX handlers are registered on `admin-ajax.php` requests:

```php
add_action( 'plugins_loaded', [ AccessControlUI::class, 'bootstrap' ] );
```

### 2. Enqueue assets

```php
add_action( 'admin_enqueue_scripts', [ $ui, 'enqueue_assets' ] );
```

### 3. Render the panel

```php
$ui->render( 'my-namespace', 'my-resource', [
    'submit_label' => __( 'Save', 'my-plugin' ),
] );
```

The panel renders a complete `<form>` element and saves through the
library-owned `wpb_access_control_save` AJAX action. Everything is wired
internally.

### 4. Authorize saves per namespace/key

```php
add_filter( 'wpb_access_control_can_save', function( bool $can_save, string $namespace, string $key, int $user_id ) {
    return 'my-namespace' === $namespace && 'my-resource' === $key;
}, 10, 4 );
```

### 5. React to a successful save

```php
add_action( 'wpb_access_control_saved', function( string $namespace, string $key, string $ac_key, array $ac_options, int $user_id ) {
    // Audit logging, cache invalidation, etc.
}, 10, 5 );
```

### Overriding the asset URL

```php
$ui->set_assets_url( plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets', __FILE__ ) );
```

---

## Registering a custom provider

```php
add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

The filter tag must match the string you passed to `AccessControlManager`.
The filter fires on `init` at priority 5 — register at priority 4 or earlier.

Each provider must extend `WPBoilerplate\AccessControl\AbstractProvider`:

| Method | Required | Description |
|--------|----------|-------------|
| `get_id(): string` | Yes | Unique slug stored as `access_control_key` |
| `get_label(): string` | Yes | Label shown in admin UI dropdown |
| `get_options(): array` | Yes | `[['id'=>'slug','label'=>'Name'], ...]` |
| `user_has_access(int $user_id, array $selected): bool` | Yes | Core check |
| `is_available(): bool` | No | Return false when a dependency is missing |

### Example provider

```php
namespace My\Plugin;

use WPBoilerplate\AccessControl\AbstractProvider;

class MembershipProvider extends AbstractProvider {

    public function get_id(): string {
        return 'my_membership';
    }

    public function get_label(): string {
        return __( 'Membership Level', 'my-plugin' );
    }

    public function get_options(): array {
        return [
            [ 'id' => 'gold',   'label' => 'Gold'   ],
            [ 'id' => 'silver', 'label' => 'Silver' ],
        ];
    }

    public function user_has_access( int $user_id, array $selected_options ): bool {
        return in_array( my_get_membership_level( $user_id ), $selected_options, true );
    }

    public function is_available(): bool {
        return function_exists( 'my_get_membership_level' );
    }
}
```

---

## Reacting to denied access

```php
add_action( 'wpb_access_control_denied', function( int $user_id, string $namespace, string $key, string $ac_key, array $options ) {
    error_log( "Access denied — user:{$user_id} {$namespace}/{$key}" );
}, 10, 5 );
```

---

## Built-in providers

| Provider ID | Class | Description |
|-------------|-------|-------------|
| `wp_role` | `WpRoleProvider` | Restricts by WordPress user role. Administrator is excluded from options (always bypassed). |
| `wp_user` | `WpUserProvider` | Restricts to a specific list of WordPress users, selected via AJAX search. |

### `WpRoleProvider` filters

| Filter | Description |
|--------|-------------|
| `wpb_access_control_wp_role_options` | Modify the list of selectable role options |
| `wpb_access_control_wp_role_has_access` | Override the final role-based access decision |

### `WpUserProvider`

Options contain **user IDs stored as strings** — `sanitize_key()` strips `@`
and `.`, so emails would be corrupted; numeric ID strings survive unchanged.

**Static helpers (optional advanced use):**

```php
use WPBoilerplate\AccessControl\WpUserProvider;

$results = WpUserProvider::search_users( 'jane' );
// → [['id'=>'5','login'=>'jane','email'=>'jane@example.com','display_name'=>'Jane Doe'], ...]

$users = WpUserProvider::get_users_by_ids( ['5', '42'] );
```

**Filter:**

| Filter | Description |
|--------|-------------|
| `wpb_access_control_wp_user_has_access` | Override the final per-user access decision |

---

## Important notes

### Filter tag isolation
Always pass a plugin-specific tag to `AccessControlManager`. If two plugins
both use the default `'wpb_access_control_providers'`, their providers will
bleed into each other's admin UIs and enforcement logic.

### Table management
BerlinDB handles all table creation and upgrades automatically via `admin_init`.
No activation hook or `maybe_create_table()` call is required. Instantiating
`new AccessControlManager(...)` (or `new RuleQuery()`) is sufficient.

### Caching
BerlinDB manages its own object cache. Always use `RuleQuery::set_rule()` and
`clear_rule()` — never write to the table via raw `$wpdb`. Direct writes
bypass the cache.

### Administrator bypass is unconditional
Any user with `manage_options` always returns `true` from `user_has_access()`
regardless of the stored rule.

### Uninstall cleanup
Each consuming plugin is responsible for removing its own rows:

```php
// In uninstall.php
( new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery() )
    ->purge_namespace( 'my-namespace' );
```

### Multisite
The table uses `$wpdb->prefix` — each sub-site has its own
`{prefix}wpb_access_control` table. Network-wide rules are not supported
and must be handled by the consuming plugin.

---

## Database table reference

Table: `{prefix}wpb_access_control`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `namespace` | VARCHAR(100) | Plugin-scoped prefix |
| `key` | VARCHAR(255) | Resource identifier |
| `access_control_key` | VARCHAR(100) | Rule type slug (same for all rows of a resource) |
| `access_control_value` | VARCHAR(255) | One option per row; `''` for the `everyone` sentinel |
| `created_at` | DATETIME | BerlinDB-managed |
| `updated_at` | DATETIME | BerlinDB-managed |

Indexes: `PRIMARY KEY (id)`, `UNIQUE (namespace, key(191), access_control_value)`, `KEY (namespace, key(191))`
