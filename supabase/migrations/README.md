# PostgreSQL migrations

`../production-schema.sql` is the single bootstrap source for a new WTFCode database and records `202608200001_initial_production` in `schema_migrations`.

Add future upgrades here as ordered `<version>_<description>.sql` files. `php tools/migrate.php` bootstraps an empty database from the production schema, then applies pending files in lexical order. Do not edit an already-applied migration.
