# xentask-core

Shared PHP model layer for XenTask — contains all core data models used across the XenTask application services.

## Models

| File | Purpose |
|------|---------|
| `Models/Base_Model.php` | Base model with shared DB query helpers |
| `Models/Loader.php` | Auto-loads all models |
| `Models/xenWorkspace.php` | Workspace management |
| `Models/xenSpace.php` | Space (project container) management |
| `Models/xenList.php` | List management |
| `Models/xenTasks.php` | Task CRUD and querying |
| `Models/xenComment.php` | Task comments |
| `Models/xenAttachment.php` | File attachments |
| `Models/xenCustomField.php` | Custom field definitions |
| `Models/xenDataTables.php` | DataTables server-side processing |
| `Models/xenFolder.php` | Folder hierarchy |
| `Models/xenStatus.php` | Task status management |
| `Models/xenUser.php` | User management |

## Usage

This library is consumed as a Git submodule. Add it to your project:

```bash
git submodule add https://github.com/cwolsen7905/xentask-core inc/xentask-core
```

Then in your startup file:

```php
define('XEN_CORE', realpath('inc/xentask-core') . '/');
require_once XEN_CORE . 'Models/Loader.php';
```

## Dependencies

Requires [ubcore](https://github.com/cwolsen7905/ubcore) for database access (`LIB_CORE` must be defined before loading models).

## License

MIT
