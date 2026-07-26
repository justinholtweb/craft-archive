<?php

namespace justinholtweb\archive\models;

use Generator;
use RuntimeException;

/**
 * Holds an export's records on disk rather than in memory.
 *
 * Collectors can push a hundred thousand records through this without the process growing,
 * because each one is written straight out as a line of NDJSON and forgotten. Writers read
 * them back a record at a time. The scratch files live outside the staging directory, so
 * they never end up in the bundle.
 */
class RecordStore
{
    /**
     * @var array<string, resource> Append handles, keyed by record type.
     */
    private array $handles = [];

    /**
     * @var array<string, int> Records written per type, in the order the types appeared.
     */
    private array $counts = [];

    public function __construct(
        private readonly string $dir,
    ) {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Couldn’t create the spool directory at {$this->dir}.");
        }
    }

    /**
     * Appends a record.
     */
    public function add(string $type, array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new RuntimeException("Couldn’t encode a $type record: " . json_last_error_msg());
        }

        fwrite($this->handle($type), $json . "\n");
        $this->counts[$type] = ($this->counts[$type] ?? 0) + 1;
    }

    /**
     * Registers a type that produced no records, so it still shows up as zero rather than
     * disappearing from the manifest.
     */
    public function register(string $type): void
    {
        $this->counts[$type] ??= 0;
    }

    /**
     * @return string[] Every type, in the order they were first seen.
     */
    public function types(): array
    {
        return array_keys($this->counts);
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    public function count(string $type): int
    {
        return $this->counts[$type] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Reads a type's records back, one at a time.
     *
     * @return Generator<int, array>
     */
    public function each(string $type): Generator
    {
        $this->flush();

        $path = $this->path($type);

        if (!is_file($path)) {
            return;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Couldn’t read the spooled $type records.");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\n");

                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);

                if (is_array($record)) {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Every record across every type, paired with its type.
     *
     * @return Generator<int, array{0: string, 1: array}>
     */
    public function eachOfAll(): Generator
    {
        foreach ($this->types() as $type) {
            foreach ($this->each($type) as $record) {
                yield [$type, $record];
            }
        }
    }

    /**
     * Makes sure everything written so far is readable.
     */
    public function flush(): void
    {
        foreach ($this->handles as $handle) {
            fflush($handle);
        }
    }

    /**
     * Closes the append handles and removes the scratch directory.
     */
    public function cleanup(): void
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->handles = [];

        if (!is_dir($this->dir)) {
            return;
        }

        // The spool only ever holds files this class wrote, so a flat sweep is enough —
        // and it keeps this class free of any dependency on a running Craft.
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.ndjson') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    /**
     * @return resource
     */
    private function handle(string $type)
    {
        if (!isset($this->handles[$type])) {
            $handle = fopen($this->path($type), 'w+');

            if ($handle === false) {
                throw new RuntimeException("Couldn’t open a spool file for $type records.");
            }

            $this->handles[$type] = $handle;
        }

        return $this->handles[$type];
    }

    private function path(string $type): string
    {
        // Types come from collector keys, which are developer-defined, so keep them to
        // something that's definitely a safe filename.
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $type);

        return $this->dir . DIRECTORY_SEPARATOR . $safe . '.ndjson';
    }
}
