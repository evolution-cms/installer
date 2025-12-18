# Evolution CMS Installer

CLI tool for creating new Evolution CMS projects.

## Installation

### Global Installation

Install globally using Composer:

```bash
composer global require evolution-cms/installer
```

**Important:** After installation, make sure the Composer global bin directory is in your system `PATH` so the `evo` command is accessible from anywhere.

#### Linux / macOS

Add to your shell configuration file (`~/.bashrc`, `~/.zshrc`, or `~/.profile`):

```bash
export PATH="$HOME/.composer/vendor/bin:$PATH"
# or for newer Composer installations:
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
evo --version
```

If you see the version, installation was successful!

## Usage

### Create a new Evolution CMS project

```bash
evo new my-project
```

This command will:
- Prompt you for database configuration
- Prompt you for admin user credentials
- Download and install Evolution CMS
- Configure the database connection
- Run migrations and seeders
- Create the admin user

### Command Options

```bash
evo new my-project --preset=evolution
evo new my-project --database=mysql
evo new my-project --database-host=localhost --database-name=evo_db
evo new my-project --admin-username=admin --admin-email=admin@example.com
evo new my-project --language=en
evo new my-project --git  # Initialize a Git repository
evo new my-project --force  # Force install even if directory exists
```

### Available Options

- `--preset`: The preset to use (default: `evolution`)
- `--database`: Database type (`mysql` or `pgsql`)
- `--database-host`: Database host (default: `localhost`)
- `--database-port`: Database port
- `--database-name`: Database name
- `--database-user`: Database user
- `--database-password`: Database password
- `--admin-username`: Admin username (default: `admin`)
- `--admin-email`: Admin email
- `--admin-password`: Admin password
- `--language`: Installation language (default: `en`)
- `--git`: Initialize a Git repository and create initial commit
- `--force`: Force install even if directory exists

## Presets

### Evolution Preset (Default)

Standard Evolution CMS installation with all core features.

```bash
evo new my-project
# or
evo new my-project --preset=evolution
```

### Custom Presets

You can create custom presets by extending the `Preset` class. See the `src/Presets/` directory for examples.

## Database Collation Handling

The installer includes smart collation handling that:
- Automatically detects the database collation
- Uses the database collation if it's not available in the server's collation list (e.g., `utf8mb4_uca1400_ai_ci`)
- Falls back to recommended collations if needed

This solves issues where databases have collations that aren't listed in `SHOW COLLATION` but are valid for the database.

## Features

### Automatic Version Resolution

- **PHP Compatibility Check**: Automatically finds the latest Evolution CMS version compatible with your PHP version
- **Version Validation**: Validates PHP version requirements before installation starts
- **Smart Version Selection**: Checks GitHub releases and composer.json requirements to determine compatibility
- **Latest Compatible Version**: Always installs the newest version that works with your PHP setup

### Smart Database Configuration

- **Automatic Port Detection**: Automatically uses correct default ports (3306 for MySQL, 5432 for PostgreSQL)
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
- Composer
- MySQL or PostgreSQL

### Installation for Development

```bash
git clone https://github.com/evolution-cms/installer.git
cd installer
composer install
```

### Running Tests

Tests are not yet configured. To add PHPUnit tests:

```bash
composer require --dev phpunit/phpunit ^11.0
```

Then update the `test` script in `composer.json` to run PHPUnit:

```json
"scripts": {
  "test": "phpunit"
}
```

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
