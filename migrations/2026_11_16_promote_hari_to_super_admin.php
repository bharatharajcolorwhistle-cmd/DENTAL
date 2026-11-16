<?php
/**
 * Migration: promote user "Hari" (harimadhavr@gmail.com) to Super Admin.
 *
 * Data-only migration: moves dcmt_users.dcmt_role from "admin" to "super_admin"
 * for the account with this email/username. Safe to run more than once.
 *
 * Browser (log in as an administrator first):
 *   {your_app_url}/migrations/2026_11_16_promote_hari_to_super_admin.php
 *
 * CLI (optional):
 *   php migrations/2026_11_16_promote_hari_to_super_admin.php
 *
 * Windows (XAMPP), example:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\dev_dental\migrations\2026_11_16_promote_hari_to_super_admin.php
 */

declare(strict_types=1);

require_once __DIR__ . '/migration_io.php';

dcmt_migration_prepare_output();

const DCMT_PROMOTE_USERNAME = 'Hari';
const DCMT_PROMOTE_EMAIL = 'harimadhavr@gmail.com';
const DCMT_PROMOTE_ROLE = 'super_admin';

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

dcmt_migration_require_admin_web();

if (!isset($dcmt_db) || !$dcmt_db instanceof Dcmt_Database) {
    dcmt_migration_write_err("Migration failed: database bootstrap did not initialize Dcmt_Database.\n");
    exit(1);
}

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    dcmt_migration_write_err("Migration failed: PDO not available.\n");
    exit(1);
}

try {
    // 1. Make sure dcmt_users.dcmt_role accepts the Super Admin role.
    //    (No-op on installations that have already run the schema migrations.)
    $dcmt_db->ensureUserRoles();

    $roleColumn = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_role'")->fetch(PDO::FETCH_ASSOC);
    $roleType = (string) ($roleColumn['Type'] ?? '');
    if ($roleType === '' || stripos($roleType, 'super_admin') === false) {
        throw new RuntimeException(
            "dcmt_users.dcmt_role does not accept 'super_admin'. Run migrations/run_schema_migrations.php first."
        );
    }

    // 2. Find the account by email (unique column), then sanity-check the username
    //    so a typo/different account can never be promoted silently.
    $stmt = $dcmt_pdo->prepare(
        "SELECT dcmt_id, dcmt_username, dcmt_email, dcmt_role, dcmt_status
           FROM dcmt_users
          WHERE LOWER(dcmt_email) = LOWER(?)
          LIMIT 1"
    );
    $stmt->execute([DCMT_PROMOTE_EMAIL]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException(
            'No user found with email ' . DCMT_PROMOTE_EMAIL . '. Nothing was changed.'
        );
    }

    if (strcasecmp(trim((string) $user['dcmt_username']), DCMT_PROMOTE_USERNAME) !== 0) {
        throw new RuntimeException(sprintf(
            'User with email %s has username "%s", not "%s". Refusing to change the role; verify the account first.',
            DCMT_PROMOTE_EMAIL,
            (string) $user['dcmt_username'],
            DCMT_PROMOTE_USERNAME
        ));
    }

    $userId = (int) $user['dcmt_id'];
    $previousRole = (string) $user['dcmt_role'];
    $status = (string) $user['dcmt_status'];

    if ($previousRole === DCMT_PROMOTE_ROLE) {
        dcmt_migration_write_out(sprintf(
            "OK: user %s (%s, id %d) already has role %s. Nothing to change.\n",
            (string) $user['dcmt_username'],
            (string) $user['dcmt_email'],
            $userId,
            DCMT_PROMOTE_ROLE
        ));
        exit(0);
    }

    // 3. Promote the account. Status is deliberately left untouched.
    $update = $dcmt_pdo->prepare("UPDATE dcmt_users SET dcmt_role = ? WHERE dcmt_id = ?");
    $update->execute([DCMT_PROMOTE_ROLE, $userId]);

    // 4. Verify the new role in the database.
    $verify = $dcmt_pdo->prepare("SELECT dcmt_role FROM dcmt_users WHERE dcmt_id = ? LIMIT 1");
    $verify->execute([$userId]);
    $newRole = (string) $verify->fetchColumn();

    if ($newRole !== DCMT_PROMOTE_ROLE) {
        throw new RuntimeException(sprintf(
            'Update did not persist for user id %d (role is still "%s").',
            $userId,
            $newRole
        ));
    }

    dcmt_migration_write_out(sprintf(
        "OK: user %s (%s, id %d) role changed from \"%s\" to \"%s\".\n",
        (string) $user['dcmt_username'],
        (string) $user['dcmt_email'],
        $userId,
        $previousRole,
        $newRole
    ));

    if ($status !== 'active') {
        dcmt_migration_write_out(sprintf(
            "WARNING: this account has status \"%s\"; the role was updated but the user cannot sign in until it is active.\n",
            $status
        ));
    }
} catch (Throwable $e) {
    dcmt_migration_write_err('Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
