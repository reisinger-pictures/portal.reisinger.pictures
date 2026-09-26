<?php

namespace App\Support;

/**
 * Resolves the scratch directory for a single consumer.
 *
 * The base temp directory is one absolute path, and every consumer used to
 * write its files straight into it under a flat namespace. Two consequences,
 * both observed in production-shaped test runs rather than reasoned about:
 *
 * 1. Consumers can collide with each other. Two different processes writing
 *    AT_postal.txt into the same directory overwrite each other, and the
 *    outcome depends on interleaving.
 * 2. Tests cannot be isolated. The location importer and its test suite both
 *    use AT_postal.zip, AT_postal.txt, AT_places.*, countryInfo.txt and the
 *    .part staging files. ParaTest runs separate test classes in separate
 *    worker processes, so the two classes shared one flat file namespace and
 *    produced intermittent failures whose symptom depended on which worker
 *    won the race — a missing file in one run, a non-zero import exit in the
 *    next.
 *
 * Giving each consumer its own subdirectory fixes the first problem outright
 * and makes the second one addressable: a test can point a single consumer at
 * its own path through filesystems.temp_dir without affecting any other
 * consumer.
 *
 * This class only composes paths. It deliberately does not create anything,
 * because the three callers need three different policies when creation fails:
 * AIService falls back to the system temp directory, the importer fails the
 * command, and the download controller creates the directory inline.
 */
final class TempDirectory
{
    /**
     * Absolute path of the consumer's subdirectory.
     *
     * The base is passed through the `default` argument of config() so a stale
     * config:cache — one written before the key existed — degrades to the
     * previous hardcoded path instead of resolving to an empty string, which
     * in the download controller would fail more quietly than the real path.
     */
    public static function path(string $consumer): string
    {
        $base = (string) config('filesystems.temp_dir', storage_path('app/private/temp'));
        $subdirectory = (string) config("filesystems.temp_subdirs.{$consumer}", $consumer);

        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$subdirectory;
    }
}
