# xentask-core

Shared PHP model layer for XenTask. Contains all core data models used across XenTask application services. Used as a Git submodule.

## Models

| Model | Table | Purpose |
|---|---|---|
| `xenWorkspace` | `workspaces` | Top-level workspace — contains spaces and members |
| `xenSpace` | `spaces` | Project container within a workspace |
| `xenList` | `lists` | List within a space (e.g. a column on a Kanban board) |
| `xenTasks` | `tasks` | Task CRUD, querying, and status management |
| `xenStatus` | `statuses` | Task status definitions per space |
| `xenComment` | `comments` | Comments on tasks |
| `xenAttachment` | `attachments` | File attachments on tasks |
| `xenCustomField` | `custom_fields` | Custom field definitions and values |
| `xenFolder` | `folders` | Folder hierarchy within spaces |
| `xenDataTables` | — | Server-side DataTables processing helper |
| `xenUser` | `users` | User accounts and workspace membership |
| `Base_Model` | — | Shared DB connection and query helpers |
| `Loader` | — | Includes all models in one call |

## Installation

Add as a Git submodule:

```bash
git submodule add https://github.com/cwolsen7905/xentask-core inc/xentask-core
```

Load all models via the `Loader`:

```php
define('XEN_CORE', realpath('inc/xentask-core') . '/');
require_once XEN_CORE . 'Models/Loader.php';
```

Or include individual models as needed:

```php
require_once XEN_CORE . 'Models/xenTasks.php';
$task = new xenTasks($task_hash);
```

## Dependencies

Requires [ubcore](https://github.com/cwolsen7905/ubcore) for database access. `LIB_CORE` must be defined and `Database.php` must be loaded before any model is instantiated.

```php
define('LIB_CORE', realpath('inc/ubcore') . '/');
require_once LIB_CORE . 'Database.php';
```

## Requirements

- PHP 7.4+
- MySQL database with the XenTask schema
- [ubcore](https://github.com/cwolsen7905/ubcore)

## License

MIT
