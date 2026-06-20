# WP Data CLI

CLI-first WordPress import library. Stream CSV and XLSX files into WordPress via WP-CLI.

## Requirements

- PHP 8.2+
- WordPress 7.0+
- WP-CLI

## Installation

```bash
composer require barnemax/wp-data-cli
```

Register the WP-CLI command wherever you bootstrap your plugin or theme:

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'import', \Barnemax\WpDataCli\Cli\ImportCommand::class );
}
```

## Usage

### 1. Create a mapping file

A mapping file is a plain PHP file that returns a `FieldMap` instance.

```php
<?php
// mappings/products.php

use Barnemax\WpDataCli\Mapper\FieldMap;

return FieldMap::make('products')
    ->id('sku')                                          // upsert key — stored as _products_id meta
    ->title('title')
    ->content('content')
    ->meta('price', 'price', transform: fn($v) => (float) $v)
    ->meta('rating', 'rating', transform: fn($v) => (float) $v)
    ->taxonomy('product_type', 'product_type_terms')     // "Electronics > Audio, Bestseller"
    ->featuredImage('image_url');
```

### 2. Run the import

```bash
# Dry run — validate without writing
wp import run --file=products.xlsx --mapping=mappings/products.php --dry-run

# Live import into a custom post type
wp import run --file=products.xlsx --mapping=mappings/products.php --post-type=product

# Pipe-delimited CSV with a custom batch size
wp import run --file=products.csv --mapping=mappings/products.php --delimiter='|' --batch=100

# Resume after a partial run (skip first 500 rows)
wp import run --file=products.xlsx --mapping=mappings/products.php --offset=500

# Continue past row errors instead of aborting
wp import run --file=products.xlsx --mapping=mappings/products.php --continue-on-error

# XLSX with a named sheet
wp import run --file=products.xlsx --mapping=mappings/products.php --sheet=products
```

### All flags

| Flag | Default | Description |
|---|---|---|
| `--file=<path>` | — | Source file (CSV or XLSX) |
| `--mapping=<path>` | — | PHP file returning a `FieldMap` |
| `--post-type=<type>` | `post` | WordPress post type |
| `--batch=<n>` | `50` | Rows per batch |
| `--sheet=<name>` | active sheet | XLSX worksheet name |
| `--encoding=<charset>` | `UTF-8` | CSV source encoding |
| `--delimiter=<char>` | `,` | CSV column delimiter |
| `--offset=<n>` | `0` | Data rows to skip |
| `--dry-run` | off | Validate without writing |
| `--continue-on-error` | off | Skip failures, don't abort |

## Idempotency

Every import is an upsert. The value of the `->id()` column is stored as post meta (`_products_id` by default). Re-running the same file updates existing posts rather than creating duplicates.

## Taxonomy format

Taxonomy cells support comma-separated terms and `>` for parent–child hierarchy:

```
Electronics > Audio, Bestseller
```

Resolves to two terms: `Audio` (child of `Electronics`) and `Bestseller`. Missing terms are created automatically.
