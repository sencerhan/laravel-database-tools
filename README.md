# Laravel DB Tools

Generate, synchronize and manage Laravel migration and seeder files from existing database tables.

## Features

### Migration Generator (`migrations:from-database`)
- Supports all MySQL column types including spatial types
- Auto-detects relationships and creates foreign keys
- Handles unique and normal indexes
- Native PHP 8.1+ enum support with class generation
- Preserves column comments and default values
- Special handling for UUID, IP, and MAC address fields

### Seeder Generator (`seeders:from-database`)
- Creates individual seeder files for each table
- Updates DatabaseSeeder automatically
- Preserves data types and handles NULL values
- Special handling for timestamps and binary data
- Properly escapes special characters

### Migration Checker (`migrations:check-and-save`)
- Registers existing tables in migrations table
- Prevents duplicate table creation attempts
- Maintains proper migration batch ordering
- Perfect for legacy database integration

### Database Synchronizer (`db:fetch`)
- Updates existing database tables to match migration files
- Adds missing columns defined in migrations
- Modifies column properties to match migration definitions
- Adds or updates indexes from migration files
- Shows detailed information about detected changes

## Installation

Add the package to your project using Composer:

```bash
composer require sencerhan/laravel-db-tools
```

For Laravel 8 and above, the ServiceProvider will be automatically registered.

## Usage

### 1. Creating Migrations From Database

To create migration files for all tables in the database:

```bash
php artisan migrations:from-database
```

To create migration files for specific tables:

```bash
php artisan migrations:from-database --tables=users,posts,comments
```

To create migration files excluding specific tables:

```bash
php artisan migrations:from-database --without_tables=logs,temp_data
```

### 2. Creating Seeders From Database

To create seeder files for all tables in the database:

```bash
php artisan seeders:from-database
```

To create seeder files for specific tables:

```bash
php artisan seeders:from-database --tables=users,posts,comments
```

To create seeder files excluding specific tables:

```bash
php artisan seeders:from-database --without_tables=logs,temp_data
```

### 3. Checking and Saving Migrations

To check all migration files and save them to the migrations table if tables exist:

```bash
php artisan migrations:check-and-save
```

This command will:
1. Check all migration files in migrations directory
2. For each migration file, check if the corresponding table exists in database
3. If table exists and migration is not already in migrations table, save it
4. Skip migrations that are already in migrations table or whose tables don't exist

### 4. Updating Database Schema to Match Migrations

To update database tables to match your migration files:

```bash
php artisan db:fetch
```

To update specific tables only:

```bash
php artisan db:fetch --tables=users,posts
```

This command will:
1. Look at the schema defined in your migration files
2. Compare it with your current database structure
3. Generate and execute SQL to make your database match the migrations
4. Display detailed information about any detected changes

## Use Cases

1. **Legacy Database Integration**
   - Generate migrations from an existing database
   - Register those migrations to avoid migration conflicts
   - Use models with your existing tables

2. **Database Synchronization**
   - Keep development, staging, and production databases in sync
   - Update database structures based on migration files without running migrations
   - Fix inconsistencies between databases and code

3. **Data Migration and Backup**
   - Generate seeders with your actual production data
   - Create a code-based backup of your database content
   - Transfer data between environments systematically

4. **Team Collaboration**
   - Synchronize database changes made by different team members
   - Guarantee database consistency across development environments
   - Generate migrations for ad-hoc database changes

## Laravel Version Compatibility

This package is compatible with the following Laravel versions:
- Laravel 8.x
- Laravel 9.x
- Laravel 10.x
- Laravel 11.x

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.