# wpb-access-control

Extensible per-resource access control library for WordPress plugins.

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

```php
use WPBoilerplate\AccessControl\AccessControlManager;

$manager = new AccessControlManager(
    // Server fetcher — callable returning resource rows.
    // Each row must have: server_route_namespace, server_route,
    // server_slug, and access_control (JSON string or '').
    function() {
        return MyPlugin\Database\ServerTable::get_all();
    }
);
```

The manager hooks `rest_pre_dispatch` automatically and enforces access on
every REST request whose route matches a resource row returned by your fetcher.

### 2. Access control storage format

Store access control config as a JSON string in each resource row:

```json
{ "type": "wp_role", "options": ["editor", "author"] }
```

An empty string `""` means **no restriction** — all authenticated users pass.

### 3. Registering a custom provider

```php
add_filter( 'wpb_access_control_providers', function( array $providers ) {
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

### 4. Access hierarchy

1. `access_control` empty or `type = everyone` → **allow**
2. User has `manage_options` (administrator) → **always allow**
3. User not authenticated → **deny (401)**
4. No provider found for the type → **deny (403)**
5. `provider->user_has_access()` → allow or **deny (403)**

### 5. Access denied action

```php
add_action( 'wpb_access_control_denied', function( $user_id, $resource, $ac_config ) {
    // Log, notify, etc.
}, 10, 3 );
```

## Built-in providers

| Provider ID | Class | Description |
|-------------|-------|-------------|
| `wp_role` | `WpRoleProvider` | Restricts by WordPress user role. Excludes administrator from selectable options. |

## Filters

| Filter | Description |
|--------|-------------|
| `wpb_access_control_providers` | Add/remove providers (receives `AbstractProvider[]`) |
| `wpb_access_control_wp_role_options` | Modify the role options list in WpRoleProvider |
| `wpb_access_control_wp_role_has_access` | Override the WP role access decision |

## Requirements

- PHP 7.4+
- WordPress 5.9+
