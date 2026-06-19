# Database migrations

Schema changes are managed in one place:

1. **Version file:** `config/schema_version.php` — bump `DCMT_SCHEMA_VERSION` when adding upgrades in `Dcmt_Database::applySchemaUpgrades()`.
2. **Runner (use this):**
   ```bash
   php migrations/run_schema_migrations.php
   ```
   Applies legacy additive migrations plus versioned upgrades (indexes, FKs, odontogram table, payment history column, etc.).

## Legacy per-feature scripts

These older files are kept for reference; prefer `run_schema_migrations.php`:

| Script | Purpose |
|--------|---------|
| `2026_04_28_add_owner_doctor_user_ids_to_settings.php` | Owner doctor setting row |
| `2026_05_29_add_reminders_table.php` | Reminders table |
| `2026_05_29_remove_reminders_patient_id.php` | Drop `dcmt_patient_id` from reminders |

## Production

- Set environment variable `DCMT_ENV=production` on the server.
- Do **not** rely on default `admin@123` (disabled when `DCMT_IS_PRODUCTION` is true).
- Run `php migrations/run_schema_migrations.php` after each deploy that changes `config/database.php` or `config/schema_version.php`.

## Checking applied version

```sql
SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'schema_version';
```

Expected after latest upgrade: `2026_06_17_001` (odontogram problems/treatments config; drops `dcmt_dimmed`, `dcmt_zone`, `dcmt_tooth_state`).
