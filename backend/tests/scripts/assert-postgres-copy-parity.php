<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php assert-postgres-copy-parity.php <source-port> <target-port> <expected-setting-value>\n");
    exit(2);
}

$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$database = getenv('DB_DATABASE');
if (! is_string($username) || $username === '' || ! is_string($password)
    || ! is_string($database) || $database === '') {
    fwrite(STDERR, "DB_USERNAME, DB_PASSWORD, and DB_DATABASE must be set for copy parity assertions.\n");
    exit(2);
}

$sourcePort = $argv[1];
$targetPort = $argv[2];
$expectedSettingValue = $argv[3];
$source = new PDO(
    "mysql:host=127.0.0.1;port={$sourcePort};dbname={$database};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true],
);
$target = new PDO(
    "pgsql:host=127.0.0.1;port={$targetPort};dbname={$database}",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true],
);

$normalize = static fn (array $rows): array => array_map(
    static fn (array $row): array => array_map(
        static fn (mixed $value): ?string => $value === null ? null : (string) $value,
        $row,
    ),
    $rows,
);
$assertSameRows = static function (string $label, array $sourceRows, array $targetRows) use ($normalize): void {
    $normalizedSource = $normalize($sourceRows);
    $normalizedTarget = $normalize($targetRows);
    usort($normalizedSource, static fn (array $left, array $right): int => $left <=> $right);
    usort($normalizedTarget, static fn (array $left, array $right): int => $left <=> $right);

    if ($normalizedSource !== $normalizedTarget) {
        throw new RuntimeException("PostgreSQL {$label} differ from the MariaDB source.");
    }
};

$assertSameRows(
    'role identities',
    $source->query('SELECT name, id FROM roles')->fetchAll(PDO::FETCH_NUM),
    $target->query('SELECT name, id FROM roles')->fetchAll(PDO::FETCH_NUM),
);
$assertSameRows(
    'role assignments',
    $source->query('SELECT user_id, role_id FROM user_roles')->fetchAll(PDO::FETCH_NUM),
    $target->query('SELECT user_id, role_id FROM user_roles')->fetchAll(PDO::FETCH_NUM),
);
$assertSameRows(
    'settings',
    $source->query('SELECT `key`, brand, value FROM settings')->fetchAll(PDO::FETCH_NUM),
    $target->query('SELECT "key", brand, value FROM settings')->fetchAll(PDO::FETCH_NUM),
);

$sourceMarker = $source->prepare('SELECT value FROM settings WHERE `key` = ? ORDER BY brand LIMIT 1');
$sourceMarker->execute(['term_editorial']);
$targetMarker = $target->prepare('SELECT value FROM settings WHERE "key" = ? ORDER BY brand LIMIT 1');
$targetMarker->execute(['term_editorial']);
if ($sourceMarker->fetchColumn() !== $expectedSettingValue || $targetMarker->fetchColumn() !== $expectedSettingValue) {
    throw new RuntimeException('The source settings sentinel did not survive the database copy exactly.');
}

fwrite(STDOUT, "PASS: PostgreSQL role identities, assignments, and settings match MariaDB ({$expectedSettingValue}).\n");
