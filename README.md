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

## Usage

### Create a new Evolution CMS project

```bash
evo install my-project
```

This command will:
- Validate PHP version compatibility
- Prompt you for database configuration (with connection testing)
- Prompt you for admin user credentials and directory
- Prompt you for installation language
- Download and install Evolution CMS (latest compatible version or from specific branch)
- Configure the database connection
- Run migrations and seeders
- Create the admin user

### Command Options

```bash
evo install my-project --preset=evolution
evo install my-project --db-type=mysql
evo install my-project --db-host=localhost --db-name=evo_db
evo install my-project --admin-username=admin --admin-email=admin@example.com
evo install my-project --admin-directory=manager
evo install my-project --language=en
evo install my-project --branch=develop  # Install from specific Git branch
evo install my-project --git  # Initialize a Git repository
evo install my-project --force  # Force install even if directory exists
```

### Available Options

- `--preset`: The preset to use (default: `evolution`)
- `--db-type`: Database type (`mysql`, `pgsql`, `sqlite`, or `sqlsrv`)
- `--db-host`: Database host (default: `localhost`, not used for SQLite)
- `--db-port`: Database port (defaults: 3306 for MySQL, 5432 for PostgreSQL, 1433 for SQL Server)
- `--db-name`: Database name (for SQLite: path to database file, default: `database.sqlite`)
- `--db-user`: Database user (not used for SQLite)
- `--db-password`: Database password (not used for SQLite)
- `--admin-username`: Admin username
- `--admin-email`: Admin email
- `--admin-password`: Admin password
- `--admin-directory`: Admin directory name (default: `manager`)
- `--language`: Installation language (default: `en`)
- `--branch`: Install from specific Git branch (e.g., `develop`, `nightly`, `main`) instead of latest release
- `--git`: Initialize a Git repository and create initial commit
- `--force`: Force install even if directory exists

## Presets

### Evolution Preset (Default)

Standard Evolution CMS installation with all core features.

```bash
evo install my-project
# or
evo install my-project --preset=evolution
```

### Custom Presets

You can create custom presets by extending the `Preset` class. See the `src/Presets/` directory for examples.

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
# or
make install
```

Other commands:

```bash
go run ./cmd/evo doctor
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
