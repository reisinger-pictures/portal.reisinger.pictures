<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Makes the contract signer identity a durable database invariant.
 *
 * A direct contract is identified by contract:<contract UUID>. Every instance
 * copied from a template is identified by template:<template UUID>, and that
 * value is stored on the signer as an immutable snapshot. The migration first
 * resolves and validates every existing row, then backfills the two canonical
 * fields, and only afterwards creates the unique index and writer guards.
 *
 * Legacy duplicates are never deleted or merged. A bounded report is written
 * to the application log and included in a RuntimeException so an operator can
 * resolve the source rows before retrying the migration. MySQL/MariaDB and
 * PostgreSQL use physical NOT NULL columns; SQLite keeps the columns nullable
 * because SQLite cannot alter nullability without rebuilding the table, while
 * equivalent BEFORE INSERT/UPDATE triggers reject null, mismatched email/scope
 * values, invalid contract-template relations, and changed identities.
 */
return new class extends Migration
{
    private const NORMALIZED_EMAIL_COLUMN = 'normalized_email';

    private const JOIN_SCOPE_KEY_COLUMN = 'join_scope_key';

    private const IDENTITY_UNIQUE_INDEX = 'contract_signers_scope_normalized_email_unique';

    private const MAX_REPORT_GROUPS = 20;

    private const MAX_REPORT_IDS = 20;

    public function up(): void
    {
        $this->assertSupportedDriver();

        try {
            // A retry may encounter guards left behind by an earlier partial
            // run. Remove them only inside the maintenance-window migration;
            // the catch block restores the fail-closed guards on every failure.
            $this->removeWriterGuards();
            $this->ensureCanonicalColumns();
            $this->preflightAndBackfill();
            $this->enforceRequiredColumns();
            $this->ensureUniqueIndex();
            $this->ensureWriterGuards();
        } catch (Throwable $exception) {
            try {
                $this->ensureWriterGuards();
            } catch (Throwable $guardException) {
                throw new RuntimeException(
                    'V039 writer-guard recovery failed after migration failure: '.$guardException->getMessage(),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('V039 does not support database driver ['.$driver.'].');
        }
    }

    private function ensureCanonicalColumns(): void
    {
        if (! Schema::hasColumn('contract_signers', self::NORMALIZED_EMAIL_COLUMN)) {
            Schema::table('contract_signers', function (Blueprint $table): void {
                $table->string(self::NORMALIZED_EMAIL_COLUMN, 255)->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('contract_signers', self::JOIN_SCOPE_KEY_COLUMN)) {
            Schema::table('contract_signers', function (Blueprint $table): void {
                $table->string(self::JOIN_SCOPE_KEY_COLUMN, 64)->nullable()->after(self::NORMALIZED_EMAIL_COLUMN);
            });
        }
    }

    /**
     * Resolve all identities before writing any backfill value. The transaction
     * makes a failed duplicate/malformed preflight leave all original emails,
     * timestamps, tokens, and audit rows untouched.
     */
    private function preflightAndBackfill(): void
    {
        DB::transaction(function (): void {
            $updates = [];
            $groups = [];
            $malformed = [];
            $malformedCount = 0;

            $query = DB::table('contract_signers as cs')
                ->leftJoin('contracts as c', 'c.id', '=', 'cs.contract_id')
                ->leftJoin('contracts as t', 't.id', '=', 'c.template_id')
                ->select([
                    'cs.id as signer_id',
                    'cs.email as signer_email',
                    'cs.normalized_email as stored_normalized_email',
                    'cs.join_scope_key as stored_join_scope_key',
                    'c.id as resolved_contract_id',
                    'c.type as contract_type',
                    'c.join_token as contract_join_token',
                    'c.template_id as contract_template_id',
                    't.id as resolved_template_id',
                    't.type as template_type',
                ]);

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                // UUIDs are stored in a case-insensitive MySQL collation by
                // default. BINARY ordering keeps the report and backfill
                // sequence deterministic across retries and drivers.
                $query->orderByRaw('BINARY `cs`.`id`');
            } else {
                $query->orderBy('cs.id');
            }

            $query->chunk(500, function ($rows) use (&$updates, &$groups, &$malformed, &$malformedCount): void {
                foreach ($rows as $row) {
                    $this->inspectSignerRow($row, $updates, $groups, $malformed, $malformedCount);
                }
            });

            if ($malformedCount > 0) {
                $this->abortPreflight($this->malformedReport($malformed, $malformedCount));
            }

            $duplicateGroups = array_filter(
                $groups,
                static fn (array $signerIds): bool => count($signerIds) > 1,
            );

            if ($duplicateGroups !== []) {
                $this->abortPreflight($this->duplicateReport($duplicateGroups));
            }

            foreach (array_chunk($updates, 250, true) as $batch) {
                foreach ($batch as $signerId => $identity) {
                    $updated = DB::table('contract_signers')
                        ->where('id', $signerId)
                        ->update($identity);

                    if ($updated !== 1) {
                        throw new RuntimeException(
                            "V039 contract signer backfill stopped before identity update for signer [{$signerId}]."
                        );
                    }
                }
            }
        }, 1);
    }

    /**
     * @param  array<string, array{normalized_email: string, join_scope_key: string}>  $updates
     * @param  array<string, list<string>>  $groups
     * @param  list<string>  $malformed
     */
    private function inspectSignerRow(
        object $row,
        array &$updates,
        array &$groups,
        array &$malformed,
        int &$malformedCount,
    ): void {
        $signerId = is_string($row->signer_id) ? $row->signer_id : '(unknown)';

        try {
            $classifiedScope = $this->classifyScopeKey($row);
            $normalizedEmail = $this->normalizeLegacyEmail($row->signer_email);
            $storedNormalizedEmail = $row->stored_normalized_email;

            if ($storedNormalizedEmail !== null
                && (! is_string($storedNormalizedEmail)
                    || $storedNormalizedEmail !== $normalizedEmail)) {
                throw new RuntimeException('stored normalized_email is not canonical');
            }

            $storedScope = $row->stored_join_scope_key;
            if ($storedScope === null) {
                $scopeKey = $classifiedScope;
            } else {
                $scopeKey = $this->validateScopeKey($storedScope);
                $this->validateStoredScopeClassification($scopeKey, $classifiedScope);
            }
        } catch (Throwable $exception) {
            $malformedCount++;
            if (count($malformed) < self::MAX_REPORT_GROUPS) {
                $malformed[] = sprintf(
                    'signer_id=%s contract_id=%s reason=%s',
                    $signerId,
                    is_string($row->resolved_contract_id) ? $row->resolved_contract_id : '(missing)',
                    $exception->getMessage(),
                );
            }

            return;
        }

        $identityKey = $scopeKey."\0".$normalizedEmail;
        $groups[$identityKey][] = $signerId;

        $identity = [
            self::NORMALIZED_EMAIL_COLUMN => $normalizedEmail,
            self::JOIN_SCOPE_KEY_COLUMN => $scopeKey,
        ];

        if ($row->stored_normalized_email !== $identity[self::NORMALIZED_EMAIL_COLUMN]
            || $row->stored_join_scope_key !== $identity[self::JOIN_SCOPE_KEY_COLUMN]) {
            $updates[$signerId] = $identity;
        }
    }

    private function normalizeLegacyEmail(mixed $email): string
    {
        if (! is_string($email)) {
            throw new RuntimeException('email is not a string');
        }

        $normalized = Str::lower(trim($email));
        if ($normalized === '' || strlen($normalized) > 255 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('email is not a valid canonical identity');
        }

        return $normalized;
    }

    private function classifyScopeKey(object $row): string
    {
        if (! $this->isUuid($row->resolved_contract_id) || $row->contract_type !== 'contract') {
            throw new RuntimeException('signer is attached to a missing or non-contract row');
        }

        if ($row->contract_template_id !== null) {
            if (! $this->isUuid($row->contract_template_id)
                || ! $this->isUuid($row->resolved_template_id)
                || $row->template_type !== 'template') {
                throw new RuntimeException('template UUID/relation is missing or malformed');
            }

            return 'template:'.$row->contract_template_id;
        }

        // A NULL/empty join token cannot distinguish a direct draft from a
        // template instance whose template was deleted. Never guess a direct
        // scope for that historical shape, even if a snapshot was present.
        if (! is_string($row->contract_join_token) || trim($row->contract_join_token) === '') {
            throw new RuntimeException('ambiguous legacy contract: template_id and join_token are both empty');
        }

        return 'contract:'.$row->resolved_contract_id;
    }

    private function validateStoredScopeClassification(string $storedScope, string $classifiedScope): void
    {
        // A template snapshot may point to an older, retained template after
        // reparenting. Preserve that valid snapshot; only its scope family is
        // checked here, never a recomputed template UUID.
        if (str_starts_with($classifiedScope, 'template:')) {
            if (! str_starts_with($storedScope, 'template:')) {
                throw new RuntimeException('stored join_scope_key does not match the known template history');
            }

            return;
        }

        if ($storedScope !== $classifiedScope) {
            throw new RuntimeException('stored join_scope_key does not match the unambiguous direct classification');
        }
    }

    private function validateScopeKey(mixed $scopeKey): string
    {
        if (! is_string($scopeKey)
            || preg_match('/^(contract|template):[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $scopeKey) !== 1) {
            throw new RuntimeException('join_scope_key is malformed');
        }

        return $scopeKey;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    private function duplicateReport(array $groups): string
    {
        ksort($groups, SORT_STRING);
        $duplicateGroups = array_filter(
            $groups,
            static fn (array $signerIds): bool => count($signerIds) > 1,
        );
        $totalGroups = count($duplicateGroups);
        $totalRows = array_sum(array_map('count', $duplicateGroups));
        $shown = array_slice($duplicateGroups, 0, self::MAX_REPORT_GROUPS, true);

        $lines = [
            'V039 contract signer identity preflight failed: duplicate canonical identities.',
            "duplicate_groups={$totalGroups} duplicate_rows={$totalRows}",
            'No signers or audit records were deleted or merged. Resolve these rows before retrying V039.',
        ];

        foreach ($shown as $identity => $signerIds) {
            [$scopeKey, $normalizedEmail] = explode("\0", $identity, 2);
            sort($signerIds, SORT_STRING);
            $ids = implode(',', array_slice($signerIds, 0, self::MAX_REPORT_IDS));
            $lines[] = sprintf(
                'scope=%s normalized_email=%s count=%d signer_ids=%s',
                $scopeKey,
                $normalizedEmail,
                count($signerIds),
                $ids,
            );
        }

        if ($totalGroups > count($shown)) {
            $lines[] = sprintf('... %d additional duplicate groups omitted.', $totalGroups - count($shown));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $items
     */
    private function malformedReport(array $items, int $total): string
    {
        $lines = [
            'V039 contract signer identity preflight failed: malformed or ambiguous rows.',
            "malformed_rows={$total}",
            'No signers or audit records were deleted or merged. Correct the source rows before retrying V039.',
            ...$items,
        ];

        if ($total > count($items)) {
            $lines[] = sprintf('... %d additional malformed rows omitted.', $total - count($items));
        }

        return implode("\n", $lines);
    }

    private function abortPreflight(string $report): void
    {
        Log::error('V039 contract signer identity preflight failed', [
            'report' => $report,
        ]);

        throw new RuntimeException($report);
    }

    private function enforceRequiredColumns(): void
    {
        $remaining = DB::table('contract_signers')
            ->whereNull(self::NORMALIZED_EMAIL_COLUMN)
            ->orWhereNull(self::JOIN_SCOPE_KEY_COLUMN)
            ->count();

        if ($remaining !== 0) {
            throw new RuntimeException('V039 canonical identity columns are still nullable after backfill.');
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `contract_signers` MODIFY COLUMN `normalized_email` VARCHAR(255) NOT NULL'
            );
            DB::statement(
                'ALTER TABLE `contract_signers` MODIFY COLUMN `join_scope_key` VARCHAR(64) NOT NULL'
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE contract_signers ALTER COLUMN normalized_email TYPE VARCHAR(255), ALTER COLUMN normalized_email SET NOT NULL'
            );
            DB::statement(
                'ALTER TABLE contract_signers ALTER COLUMN join_scope_key TYPE VARCHAR(64), ALTER COLUMN join_scope_key SET NOT NULL'
            );

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite cannot alter column nullability without rebuilding the
            // table. The triggers installed below provide the same required
            // and immutable writer contract without risking existing indexes.
            return;
        }

        // Keep a deliberate fallback for the remaining Laravel-supported SQL
        // Server connection instead of silently leaving nullable identities.
        Schema::table('contract_signers', function (Blueprint $table): void {
            $table->string(self::NORMALIZED_EMAIL_COLUMN, 255)->nullable(false)->change();
            $table->string(self::JOIN_SCOPE_KEY_COLUMN, 64)->nullable(false)->change();
        });
    }

    private function ensureUniqueIndex(): void
    {
        if ($this->hasExpectedUniqueIndex()) {
            return;
        }

        if (Schema::hasIndex('contract_signers', self::IDENTITY_UNIQUE_INDEX)) {
            Schema::table('contract_signers', function (Blueprint $table): void {
                $table->dropIndex(self::IDENTITY_UNIQUE_INDEX);
            });
        }

        Schema::table('contract_signers', function (Blueprint $table): void {
            $table->unique(
                [self::JOIN_SCOPE_KEY_COLUMN, self::NORMALIZED_EMAIL_COLUMN],
                self::IDENTITY_UNIQUE_INDEX,
            );
        });
    }

    private function hasExpectedUniqueIndex(): bool
    {
        foreach (Schema::getIndexes('contract_signers') as $index) {
            if (($index['name'] ?? null) !== self::IDENTITY_UNIQUE_INDEX) {
                continue;
            }

            $columns = $index['columns'] ?? [];
            if (is_string($columns)) {
                $columns = explode(',', $columns);
            }
            $columns = array_map(
                static fn (mixed $column): string => strtolower(trim((string) $column)),
                $columns,
            );

            return (bool) ($index['unique'] ?? false)
                && $columns === [self::JOIN_SCOPE_KEY_COLUMN, self::NORMALIZED_EMAIL_COLUMN];
        }

        return false;
    }

    private function removeWriterGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard ON contract_signers');
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard ON contract_signers');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard');
        }
    }

    private function ensureWriterGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard');
            DB::statement(<<<'SQL'
                CREATE TRIGGER contract_signers_identity_insert_guard
                BEFORE INSERT ON contract_signers
                FOR EACH ROW
                BEGIN
                    DECLARE contract_type VARBINARY(20) DEFAULT NULL;
                    DECLARE contract_template_id VARBINARY(36) DEFAULT NULL;
                    DECLARE template_type VARBINARY(20) DEFAULT NULL;

                    IF NEW.email IS NULL
                        OR NEW.normalized_email IS NULL
                        OR NEW.join_scope_key IS NULL
                        OR CHAR_LENGTH(TRIM(NEW.email)) = 0
                        OR CHAR_LENGTH(TRIM(NEW.normalized_email)) = 0
                        OR LENGTH(NEW.normalized_email) > 255
                        OR CHAR_LENGTH(TRIM(NEW.join_scope_key)) = 0
                        OR BINARY NEW.normalized_email <> BINARY LOWER(TRIM(NEW.email))
                        OR BINARY NEW.normalized_email <> BINARY LOWER(TRIM(NEW.normalized_email))
                        OR BINARY NEW.join_scope_key <> BINARY LOWER(TRIM(NEW.join_scope_key))
                        OR CHAR_LENGTH(NEW.join_scope_key) <> 45
                        OR (SUBSTRING(NEW.join_scope_key, 1, 9) <> 'contract:'
                            AND SUBSTRING(NEW.join_scope_key, 1, 9) <> 'template:')
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'contract_signer_identity_required';
                    END IF;

                    -- MariaDB may expose UUID columns as 16-byte values. Cast
                    -- to CHAR first so the binary local values remain textual
                    -- 36-character UUIDs and cannot mix table collations.
                    SELECT
                        CAST(CAST(MAX(c.type) AS CHAR) AS BINARY),
                        CAST(CAST(MAX(c.template_id) AS CHAR) AS BINARY)
                        INTO contract_type, contract_template_id
                        FROM contracts c
                        WHERE CAST(c.id AS BINARY) = CAST(NEW.contract_id AS BINARY);

                    IF contract_type IS NULL OR BINARY contract_type <> BINARY 'contract' THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'contract_signer_identity_invalid';
                    END IF;

                    IF contract_template_id IS NULL THEN
                        IF BINARY NEW.join_scope_key <> BINARY CONCAT('contract:', LOWER(CAST(NEW.contract_id AS CHAR))) THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'contract_signer_identity_invalid';
                        END IF;
                    ELSE
                        SELECT CAST(CAST(MAX(t.type) AS CHAR) AS BINARY)
                            INTO template_type
                            FROM contracts t
                            WHERE CAST(CAST(t.id AS CHAR) AS BINARY) = contract_template_id;

                        IF template_type IS NULL
                            OR BINARY template_type <> BINARY 'template'
                            OR BINARY NEW.join_scope_key <> BINARY CONCAT('template:', LOWER(CAST(contract_template_id AS CHAR)))
                        THEN
                            SIGNAL SQLSTATE '45000'
                                SET MESSAGE_TEXT = 'contract_signer_identity_invalid';
                        END IF;
                    END IF;
                END
            SQL);
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard');
            DB::statement(<<<'SQL'
                CREATE TRIGGER contract_signers_identity_update_guard
                BEFORE UPDATE ON contract_signers
                FOR EACH ROW
                BEGIN
                    IF NEW.email IS NULL
                        OR NEW.normalized_email IS NULL
                        OR NEW.join_scope_key IS NULL
                        OR NOT (NEW.contract_id <=> OLD.contract_id)
                        OR NOT (NEW.email <=> OLD.email)
                        OR NOT (NEW.normalized_email <=> OLD.normalized_email)
                        OR NOT (NEW.join_scope_key <=> OLD.join_scope_key)
                        OR BINARY NEW.email <> BINARY OLD.email
                        OR BINARY NEW.normalized_email <> BINARY OLD.normalized_email
                        OR BINARY NEW.join_scope_key <> BINARY OLD.join_scope_key
                        OR BINARY NEW.normalized_email <> BINARY LOWER(TRIM(NEW.email))
                    THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'contract_signer_identity_immutable';
                    END IF;
                END
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard');
            DB::statement(<<<'SQL'
                CREATE TRIGGER contract_signers_identity_insert_guard
                BEFORE INSERT ON contract_signers
                BEGIN
                    SELECT RAISE(ABORT, 'contract_signer_identity_required')
                    WHERE NEW.email IS NULL
                        OR NEW.normalized_email IS NULL
                        OR NEW.join_scope_key IS NULL
                        OR LENGTH(TRIM(NEW.email)) = 0
                        OR LENGTH(TRIM(NEW.normalized_email)) = 0
                        OR LENGTH(CAST(NEW.normalized_email AS BLOB)) > 255
                        OR LENGTH(TRIM(NEW.join_scope_key)) = 0
                        OR NEW.normalized_email IS NOT LOWER(TRIM(NEW.email))
                        OR NEW.normalized_email IS NOT LOWER(TRIM(NEW.normalized_email))
                        OR NEW.join_scope_key IS NOT LOWER(TRIM(NEW.join_scope_key))
                        OR LENGTH(NEW.join_scope_key) <> 45
                        OR (SUBSTRING(NEW.join_scope_key, 1, 9) <> 'contract:'
                            AND SUBSTRING(NEW.join_scope_key, 1, 9) <> 'template:');

                    SELECT RAISE(ABORT, 'contract_signer_identity_invalid')
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM contracts c
                        WHERE c.id = NEW.contract_id
                          AND c.type = 'contract'
                          AND (
                              (
                                  c.template_id IS NULL
                                  AND NEW.join_scope_key = 'contract:' || c.id
                              )
                              OR (
                                  c.template_id IS NOT NULL
                                  AND EXISTS (
                                      SELECT 1
                                      FROM contracts t
                                      WHERE t.id = c.template_id
                                        AND t.type = 'template'
                                  )
                                  AND NEW.join_scope_key = 'template:' || c.template_id
                              )
                          )
                    );
                END
            SQL);
            DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard');
            DB::statement(<<<'SQL'
                CREATE TRIGGER contract_signers_identity_update_guard
                BEFORE UPDATE ON contract_signers
                BEGIN
                    SELECT RAISE(ABORT, 'contract_signer_identity_immutable')
                    WHERE NEW.contract_id IS NOT OLD.contract_id
                        OR NEW.email IS NOT OLD.email
                        OR NEW.normalized_email IS NOT OLD.normalized_email
                        OR NEW.join_scope_key IS NOT OLD.join_scope_key;

                    SELECT RAISE(ABORT, 'contract_signer_identity_required')
                    WHERE NEW.email IS NULL
                        OR NEW.normalized_email IS NULL
                        OR NEW.join_scope_key IS NULL
                        OR NEW.normalized_email IS NOT LOWER(TRIM(NEW.email));
                END
            SQL);

            return;
        }

        if ($driver !== 'pgsql') {
            throw new RuntimeException('V039 writer guards do not support database driver ['.$driver.'].');
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION contract_signers_identity_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.email IS NULL
                    OR NEW.normalized_email IS NULL
                    OR NEW.join_scope_key IS NULL
                    OR BTRIM(NEW.email) = ''
                    OR BTRIM(NEW.normalized_email) = ''
                    OR OCTET_LENGTH(NEW.normalized_email) > 255
                    OR BTRIM(NEW.join_scope_key) = ''
                    OR NEW.normalized_email IS DISTINCT FROM LOWER(BTRIM(NEW.email))
                    OR NEW.normalized_email IS DISTINCT FROM LOWER(BTRIM(NEW.normalized_email))
                    OR NEW.join_scope_key IS DISTINCT FROM LOWER(BTRIM(NEW.join_scope_key))
                    OR LENGTH(NEW.join_scope_key) <> 45
                    OR SUBSTRING(NEW.join_scope_key, 1, 9) NOT IN ('contract:', 'template:')
                THEN
                    RAISE EXCEPTION 'contract_signer_identity_required' USING ERRCODE = '23502';
                END IF;

                IF TG_OP = 'INSERT'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM contracts c
                        WHERE c.id = NEW.contract_id
                          AND c.type = 'contract'
                          AND (
                              (
                                  c.template_id IS NULL
                                  AND NEW.join_scope_key = 'contract:' || c.id::text
                              )
                              OR (
                                  c.template_id IS NOT NULL
                                  AND EXISTS (
                                      SELECT 1
                                      FROM contracts t
                                      WHERE t.id = c.template_id
                                        AND t.type = 'template'
                                  )
                                  AND NEW.join_scope_key = 'template:' || c.template_id::text
                              )
                          )
                    )
                THEN
                    RAISE EXCEPTION 'contract_signer_identity_invalid' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE'
                    AND (
                        NEW.contract_id IS DISTINCT FROM OLD.contract_id
                        OR NEW.email IS DISTINCT FROM OLD.email
                        OR NEW.normalized_email IS DISTINCT FROM OLD.normalized_email
                        OR NEW.join_scope_key IS DISTINCT FROM OLD.join_scope_key
                    )
                THEN
                    RAISE EXCEPTION 'contract_signer_identity_immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_insert_guard ON contract_signers');
        DB::statement(<<<'SQL'
            CREATE TRIGGER contract_signers_identity_insert_guard
            BEFORE INSERT ON contract_signers
            FOR EACH ROW
            EXECUTE FUNCTION contract_signers_identity_guard()
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS contract_signers_identity_update_guard ON contract_signers');
        DB::statement(<<<'SQL'
            CREATE TRIGGER contract_signers_identity_update_guard
            BEFORE UPDATE ON contract_signers
            FOR EACH ROW
            EXECUTE FUNCTION contract_signers_identity_guard()
        SQL);
    }
};
