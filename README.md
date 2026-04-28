# Evolution CMS Installer

CLI tool for creating new Evolution CMS projects.

## Requirements

- PHP 8.3+
- Composer 2.x

## Install

Install globally using Composer:

```bash
composer global require evolution-cms/installer
```

**Important:** Add the Composer global bin directory to your `PATH` so the `evo` command is accessible from anywhere.

#### Linux / macOS

Add to your shell configuration file (`~/.bashrc`, `~/.zshrc`, or `~/.profile`):

```bash
export PATH="$HOME/.config/composer/vendor/bin:$PATH"
```

Then reload your shell configuration:

```bash
source ~/.bashrc  # or source ~/.zshrc
```

#### Windows

1. Find your Composer global directory (usually `C:\Users\YourName\AppData\Roaming\Composer\vendor\bin`)
2. Add it to your system PATH:
   - Press `Win + R`, type `sysdm.cpl`, press Enter
   - Go to "Advanced" tab → "Environment Variables"
   - Under "System variables", find `Path` and click "Edit"
   - Add the Composer bin directory path
   - Click "OK" to save

3. Restart your terminal/command prompt

#### Verify Installation

After setting up PATH, verify the installation:

```bash
evo version
```

On first run, the `evo` PHP bootstrapper downloads the matching Go binary from GitHub Releases, verifies it via `checksums.txt`, stores it next to the bootstrapper, then runs it.

To pre-install the Go binary (optional):

```bash
evo self-install
```

## Update

Recommended:

```bash
evo self-update
```

Alternative:

```bash
composer global update evolution-cms/installer
```

## Troubleshooting

- GitHub API rate limit: set `GITHUB_TOKEN` (classic or fine-grained token with public repo access) in your environment.
- Permissions: ensure the package `bin/` directory is writable (the bootstrapper installs `bin/evo.bin` or `bin/evo.exe` there).
- Composer not found / Composer is a shell alias (common on hosting panels like Hestia): install a Composer executable on `PATH` or set `EVO_COMPOSER_BIN` to the full path, e.g. `EVO_COMPOSER_BIN=$HOME/.composer/composer evo install`.

## Usage

### Create a new Evolution CMS project

```bash
evo install
```

This command will:
- Validate PHP version compatibility
- Prompt you for the target installation directory when it was not passed as an argument
- Prompt you for database configuration (with connection testing)
- Prompt you for admin user credentials and directory
- Prompt you for installation language
- Prompt you to choose a project preset from `evolution-cms-presets` or enter a custom preset source
- Download and install Evolution CMS (latest compatible version or from specific branch)
- Configure the database connection
- Run migrations and seeders
- Create the admin user
- Apply the selected project-layer preset

### Command Options

```bash
evo install
evo install my-project
evo install my-project --preset=evolution
evo install my-project --preset=evolution-cms-presets/default
evo install my-project --preset=evolution-cms-presets/default@dev
evo install my-project --preset=evolution-cms-presets/default-daisyui
evo install my-project --db-type=mysql
evo install my-project --db-host=localhost --db-name=evo_db
evo install my-project --admin-username=admin --admin-email=admin@example.com
evo install my-project --admin-directory=manager
evo install my-project --language=en
evo install my-project --branch=develop  # Install from specific Git branch
evo install my-project --force  # Force install even if directory exists
evo install my-project --cli --log  # Non-interactive mode + write log.md
evo install my-project --composer-update  # Use composer update during setup
evo install my-project --composer-clear-cache  # Clear Composer cache before install
evo install my-project --github-pat=TOKEN  # GitHub PAT for API requests
evo install my-project --extras=sTask,sSeo  # Install extras after setup (optional)
evo install my-project --extras=legacy-store:84@1.12.2  # Install a Legacy Store package by ID
```

### Available Options

- `--preset`: Project-layer preset spec. In TUI mode, omit it to choose from the `evolution-cms-presets` catalog or enter a custom source. In CLI mode, omit it for core-only install. Use `evolution`, `default`, `evolution-cms-presets/default`, `evolution-cms-presets/default@dev`, a Git URL, or a local path.
- `--db-type`: Database type (`mysql`, `pgsql`, `sqlite`, or `sqlsrv`)
- `--db-host`: Database host (default: `localhost`, not used for SQLite)
- `--db-port`: Database port (defaults: 3306 for MySQL, 5432 for PostgreSQL, 1433 for SQL Server)
- `--db-name`: Database name (for SQLite: database file name stored under `core/database/`, default: `database.sqlite`)
- `--db-user`: Database user (not used for SQLite)
- `--db-password`: Database password (not used for SQLite). If it contains shell special characters (e.g. `;`, `&`, `!`), quote it: `--db-password='p;ass'`.
- `--admin-username`: Admin username
- `--admin-email`: Admin email
- `--admin-password`: Admin password
- `--admin-directory`: Admin directory name (default: `manager`)
- `--language`: Installation language (default: `en`)
- `--branch`: Install from specific Git branch (e.g., `3.5.x`, `develop`, `nightly`, `main`) instead of latest release
- `--force`: Force install even if directory exists
- `--log`: Always write installer log to `log.md`
- `--cli`: Run in non-interactive CLI mode (no TUI)
- `--quiet`: Reduce CLI output (warnings/errors only)
- `--composer-clear-cache`: Clear Composer cache before install
- `--composer-update`: Use `composer update` instead of `composer install` during setup
- `--github-pat` / `--github_pat`: GitHub PAT token for API requests (avoids GitHub rate limits)
- `--extras`: Comma-separated extras to install after setup. Managed extras can be passed by name (for example `sTask,sSeo`) and released packages are installed with `*` unless you pin a version. Dev-only managed packages use their default branch constraint, for example `dev-main`. Legacy Store packages can be passed by ID (for example `legacy-store:84@1.12.2`).

### CLI Example (Non-interactive)

```bash
evo install demo \
  --cli \
  --branch=3.5.x \
  --db-type=sqlite \
  --db-name=database.sqlite \
  --admin-username=admin \
  --admin-email=admin@example.com \
  --admin-password=123456 \
  --admin-directory=manager \
  --language=uk \
  --preset=evolution-cms-presets/default
```

Notes:
- `--cli` is non-interactive; use `--extras` to auto-install Extras.
- `--extras` works in both TUI and CLI; when provided, the Extras selection screen is skipped and installation starts immediately.
- Released Extras without an explicit `@version` are installed with Composer constraint `*`, so later Composer updates can pick up newer package versions. Dev-only Extras without releases use their default branch constraint, for example `dev-main`.
- Legacy Store packages are selected by their catalog ID in CLI mode, e.g. `--extras=legacy-store:84@1.12.2`.

## Project Presets

The installer separates the target project from the preset source.

For TUI installs, you can run the installer with no arguments and choose the target directory, database, admin user, language, project preset, and optional Extras inside the wizard:

```bash
evo install
```

If you already know the target directory or branch, pass only those values and let TUI ask the rest:

```bash
evo install /path/to/my-site --branch=3.5.x
```

When `--preset` is omitted in TUI mode, the installer fetches public repositories from `https://github.com/evolution-cms-presets/` and shows them as preset choices. The same screen also includes:

- `Custom repository, Git URL, or local path` for private presets or local development checkouts
- `Evolution core only (no project preset)` for a plain core install

You can still pass a preset directly when you want to skip the preset picker:

```bash
evo install /path/to/my-site \
  --branch=3.5.x \
  --preset=evolution-cms-presets/default-daisyui
```

For CLI installs, provide all required answers up front. This installs a new project into the target directory, then copies the `evolution-cms-presets/default` project layer into that project:

```bash
evo install /path/to/my-site \
  --cli \
  --branch=3.5.x \
  --db-type=sqlite \
  --db-name=database.sqlite \
  --admin-username=admin \
  --admin-email=admin@example.com \
  --admin-password=change-me \
  --admin-directory=manager \
  --language=uk \
  --preset=evolution-cms-presets/default
```

`--preset=evolution-cms-presets/default` means "copy this preset as the bootstrap project layer." It does not define the future GitHub identity of the created site. The target directory or its own Git remote can still be your own project repository.

The default preset does not install Extras. Use `--extras=sTask,sSeo` only when you intentionally want those packages in the project.

Presets can declare required managed Composer Extras in `core/custom/composer.json`. The installer reads those Composer requirements after the preset is applied, matches them against the managed Extras catalog, then runs the Extras installer so provider discovery, asset publishing, migrations, and cache clearing still happen. Required Extras are automatically selected after the preset is applied, stay locked in the TUI, and are still installed when the user chooses to skip optional Extras:

```json
{
  "require": {
    "evolution-cms/etinymce": "*"
  }
}
```

Legacy or non-Composer required Extras can still be described in `core/custom/preset.json` when needed, but Composer package dependencies should live in `core/custom/composer.json`.

Accepted preset sources:

- `default` resolves to `https://github.com/evolution-cms-presets/default.git`
- `evolution-cms-presets/default` resolves to `https://github.com/evolution-cms-presets/default.git`
- `evolution-cms-presets/default@dev` resolves to the same preset repository with Git ref `dev`
- `evolution-cms-presets/blog-daisyui` resolves to the official blog starter preset
- `owner/private-preset` can be entered in TUI as a custom source when your Git environment can access it
- full Git URLs are used as-is; add `#dev` when you need a URL ref
- local paths are used as-is

Use a branch or tag suffix only when you need to install a non-default preset ref. Local preset development can point directly at a checkout:

```bash
evo install /path/to/my-site --preset=/path/to/default-preset
```

### Evolution Core Only

Standard Evolution CMS installation with all core features.

```bash
evo install my-project --preset=evolution
```

In TUI mode, omit `--preset` and keep the default `No project preset (Evolution core only)` choice.

### Custom Project Presets

Project presets are applied through the installed Evolution CMS `core/artisan preset:install` command after the core installation and database setup complete.

## Features

### Automatic Version Resolution

- **PHP Compatibility Check**: Automatically finds the latest Evolution CMS version compatible with your PHP version
- **Version Validation**: Validates PHP version requirements before installation starts
- **Smart Version Selection**: Checks GitHub releases and composer.json requirements to determine compatibility
- **Latest Compatible Version**: Always installs the newest version that works with your PHP setup
- **Branch Installation**: Install from specific Git branches (develop, nightly, main) for development or testing

### Smart Database Configuration

- **Multiple Database Support**: Supports MySQL/MariaDB, PostgreSQL, SQLite, and SQL Server
- **Automatic Port Detection**: Automatically uses correct default ports (3306 for MySQL, 5432 for PostgreSQL, 1433 for SQL Server)
- **Connection Testing**: Tests database connection before proceeding with installation, with retry option
- **Collation Resolution**: Intelligently handles database collations, including those not in the server's collation list
- **Install Type Detection**: Automatically detects if this is a fresh install or an update
- **Secure Configuration**: Creates database config files with proper permissions (read-only)

### Managed Extras Wizard (TUI)

- **Post-install selection**: After the core installation, the installer opens the Extras selection screen directly with default Extras preselected.
- **Selection UI**: Shows bundled defaults and managed Extras first, with checkboxes, versions, descriptions, and search.
- **Legacy Store**: Legacy Store packages are hidden behind the `Show Legacy Store` action so the main list stays focused.
- **Batch install**: Installs selected Extras one-by-one via `php artisan extras extras <Name> <version>` and shows progress/status. Released managed packages default to `*`; dev-only packages default to their branch constraint such as `dev-main`.
- **Post steps**: Runs `php artisan migrate` once after all Extras, then `php artisan cache:clear-full`.
- **Flow**: Install -> Extras selection -> Progress -> Summary

### Inspired by Docker Implementation

This installer incorporates best practices from the [Evolution CMS Docker implementation](https://github.com/evolution-cms/evolution/blob/nightly/docker/entrypoint.sh), including:
- Database configuration file generation
- Install type detection
- Proper handling of MySQL and PostgreSQL differences

## Development

### Requirements

- PHP 8.3+
- Composer 2.x
- Database: MySQL 5.7+ / MariaDB 10.3+, PostgreSQL 10.0+, SQLite 3.26.0+, or SQL Server 2017+

### Installation for Development

```bash
git clone https://github.com/evolution-cms/installer.git
cd installer
composer install
```

### Run installer via Go (TUI)

Requirements: Go (see `go` version in `go.mod`).

From `installer/`:

```bash
go run ./cmd/evo install
# or prefill the Evolution branch and let TUI ask for directory, preset, DB, admin, language, and Extras:
go run ./cmd/evo install --branch=3.5.x
# or
make install
```

Other commands:

```bash
#
# Note: `evo doctor` command has been removed.
go run ./cmd/evo version
go run ./cmd/evo install -f
```

### Local Development (Built-in PHP Server)

For local development and quick testing, you can run Evolution CMS using PHP’s built-in web server.  
This approach does **not require Apache, Nginx, or PHP-FPM** and is intended for development purposes only.

#### Requirements

- PHP 8.3+
- SQLite or a running database server (MySQL / PostgreSQL)
- Installed project via `evo install`

#### Running the Development Server

Navigate to your Evolution CMS project root and run:

```bash
php -S localhost:8000
```

Then open your browser at:

http://localhost:8000

#### Recommended Entry Point (Router Script)

To ensure correct handling of friendly URLs, static assets, and the manager interface, it is strongly recommended to use a router script.

Create a file named `router.php` in the project root:

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

require __DIR__ . '/index.php';
```

Start the server using the router:

```bash
php -S localhost:8000 router.php
```

#### Using SQLite for Fast Local Setup

For the fastest local development experience, SQLite is recommended:

```bash
evo install my-project --db-type=sqlite
```

This allows you to start the project instantly without running a database server.

#### Important Notes

- Do not use the built-in PHP server in production
- No HTTPS support
- Single-threaded, no process manager
- Intended for local development, debugging, and testing only

### Running Tests

```bash
composer install
composer test
```

Or run PHPUnit directly:

```bash
vendor-php/bin/phpunit
```

Go unit tests:

```bash
go test ./...
```

Run PHP tests with coverage:

```bash
composer test-coverage
```

Note: Code coverage requires either Xdebug or PCOV PHP extension to be installed:

- **Xdebug**: `pecl install xdebug` or install via your OS package manager
- **PCOV**: `pecl install pcov` (faster alternative to Xdebug for coverage only)

After installing, restart PHP-FPM or your web server.

## Legacy Installer

The old `install.php` file is still available in this repository for backward compatibility and quick installations.

## License

GPL-3.0-or-later

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

- Documentation: https://docs.evo.im
- Issues: https://github.com/evolution-cms/installer/issues
- Community: https://t.me/evolutioncms
