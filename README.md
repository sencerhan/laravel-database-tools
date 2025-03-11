# Laravel DB Tools

Database schema synchronization tool for Laravel that updates your database structure to match your migration files.

## Features

### Database Synchronizer (`db:fetch`)
- Updates existing database tables to match migration files
- Safe schema modifications with temporary table approach
- Robust handling of all MySQL column types
- Special handling for:
  - Decimal/Float with precision/scale
  - Enum/Set with value lists
  - Foreign keys and indexes
  - Laravel's special columns (timestamps, soft deletes)
- Progress tracking and detailed logging
- Data integrity verification

### Supported Column Types
- **Numeric Types:** integer, bigInteger, decimal, float, double (with unsigned variants)
- **String Types:** char, varchar, text, mediumText, longText
- **Date/Time Types:** date, dateTime, timestamp, time (with timezone variants)
- **Special Types:** enum, set, json, binary
- **Laravel Types:** softDeletes, rememberToken, timestamps

## Installation

```bash
composer require sencerhan/laravel-db-tools
```

## Usage

### Basic Usage

To update all tables:
```bash
php artisan db:fetch
```

To update specific tables:
```bash
php artisan db:fetch --tables=users,posts
```

### Debug Mode
Show detailed information about changes:
```bash
php artisan db:fetch --debug
```

### Force Mode
Force schema changes even with constraints:
```bash
php artisan db:fetch --force
```

## Key Features

### 1. Safe Schema Updates
- Uses temporary tables for risky operations
- Verifies data integrity after changes
- Automatic rollback on failure
- Preserves foreign key relationships

### 2. Column Handling
- Precise type mapping between Laravel and MySQL
- Support for all modifiers (nullable, default, unique, etc.)
- Special handling for decimal precision/scale
- Proper enum/set value management

### 3. Constraint Management
- Safe foreign key handling
- Index recreation
- Smart constraint dependency resolution
- Automatic backup and restore

### 4. Data Protection
- Preserves data during structure changes
- Validates data integrity after modifications
- Protects special Laravel columns
- Handles NOT NULL constraints safely

## Error Handling

The tool provides detailed error messages and:
- Shows exact SQL causing problems
- Maintains data integrity during errors
- Attempts to restore constraints after failures
- Provides debug information when needed

## Examples

### Enum/Set Columns:
```php
// In your migration:
$table->enum('status', ['active', 'pending', 'cancelled']);
$table->set('permissions', ['read', 'write', 'delete']);
```

### Decimal/Float Columns:
```php
$table->decimal('amount', 8, 2);
$table->unsignedDecimal('price', 10, 2);
$table->float('score', 8, 2);
```

### Special Modifiers:
```php
$table->string('name')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
$table->decimal('price', 8, 2)->unsigned();
$table->enum('level', ['basic', 'premium'])->default('basic')->nullable();
```

## Requirements
- Laravel 8.x or higher
- PHP 8.1 or higher
- MySQL 5.7 or higher

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.