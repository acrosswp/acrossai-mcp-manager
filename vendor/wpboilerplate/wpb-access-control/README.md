# wpb-access-control

Extensible per-resource access control library for WordPress plugins.

Gate any WordPress REST API endpoint by user role (or any custom back-end)
with a provider registry that any plugin can extend.

## Installation

```bash
composer require wpboilerplate/wpb-access-control
```

Or via a VCS repository in your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/WPBoilerplate/wpb-access-control.git"
        }
    ],
    "require": {
        "wpboilerplate/wpb-access-control": "dev-main"
    }
}
```

## Usage

### 1. Instantiate the manager in your plugin bootstrap

**Simple setup** — your resource rows use the standard keys
(`route_namespace`, `route`, `access_control`):

```php
use WPBoilerplate\AccessControl\AccessControlManager;

$manager = new AccessControlManager(
    fn() => MyPlugin\Database\ResourceTable::get_all()
);
```

**Custom row shape** — pass a `$row_mapper` callable to translate your own
DB column names:

```php
use WPBoilerplate\AccessControl\AccessControlManager;

$manager = new AccessControlManager(
    fn() => MyPlugin\Database\ResourceTable::get_all(),
    'my_plugin_access_control_providers',  // custom filter tag (optional)
    function( array $row ): array {
        return array(
            'namespace'      => $row['rest_namespace'],
            'route'          => $row['rest_route'] ?: $row['slug'],
            'access_control' => $row['ac_config'] ?? '',
        );
    }
);
```

The manager hooks `rest_pre_dispatch` automatically and enforces access on
every REST request whose route matches a resource row returned by your fetcher.

### 2. Standard row shape (no mapper needed)

When no `$row_mapper` is provided, each row returned by your fetcher must have:

| Key | Type | Description |
|-----|------|-------------|
| `route_namespace` | string | REST namespace — e.g. `myplugin/v1` |
| `route` | string | REST route path — e.g. `products` |
| `access_control` | string | JSON config or empty string |

### 3. Access control storage format

Store access control config as a JSON string in each resource row:

```json
{ "type": "wp_role", "options": ["editor", "author"] }
```

An empty string `""` means **no restriction** — all users pass.

### 4. Registering a custom provider

```php
add_filter( 'wpb_access_control_providers', function( array $providers ) {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

Use your own filter tag (second constructor argument) to avoid collisions when
multiple plugins use this library on the same WordPress install:

```php
$manager = new AccessControlManager(
    fn() => MyTable::get_all(),
    'my_plugin_access_control_providers'
);

add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

Each provider must extend `WPBoilerplate\AccessControl\AbstractProvider` and
implement:

| Method | Description |
|--------|-------------|
| `get_id(): string` | Unique key stored in JSON `type` field |
| `get_label(): string` | Label shown in admin UI |
| `get_options(): array` | Selectable options `[['id'=>'...','label'=>'...'],...]` |
| `user_has_access(int $user_id, array $selected): bool` | Core check |
| `is_available(): bool` | Return false when a dependency is missing (optional) |

### 5. Access hierarchy

1. `access_control` empty or `type = everyone` → **allow**
2. User has `manage_options` (administrator) → **always allow**
3. User not authenticated → **deny (401)**
4. No provider found for the type → **deny (403)**
5. `provider->user_has_access()` → allow or **deny (403)**

### 6. Access denied action

```php
add_action( 'wpb_access_control_denied', function( $user_id, $resource, $ac_config ) {
    // Log, notify, etc.
}, 10, 3 );
```

### 7. Manual access check (outside REST context)

```php
// Check whether the currently logged-in user can access a resource.
$allowed = $manager->current_user_can_access( $resource_row );
```

## Built-in providers

| Provider ID | Class | Description |
|-------------|-------|-------------|
| `wp_role` | `WpRoleProvider` | Restricts by WordPress user role. Excludes administrator from selectable options. |

## Filters

| Filter | Description |
|--------|-------------|
| `wpb_access_control_providers` | Add/remove providers (receives `AbstractProvider[]`) — use the custom tag passed to constructor to avoid collisions |
| `wpb_access_control_wp_role_options` | Modify the role options list in `WpRoleProvider` |
| `wpb_access_control_wp_role_has_access` | Override the WP role access decision |

## Requirements

- PHP 7.4+
- WordPress 5.9+
