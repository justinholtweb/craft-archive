<?php

/**
 * Integration check: run a real export in every registered format against a live Craft,
 * then re-parse each one and compare it against the JSON reference.
 *
 * This is a script rather than a PHPUnit case on purpose — it needs a booted Craft
 * application with real content, which is a different thing from the unit suite. See
 * tests/README.md for how to run it.
 *
 * Exits non-zero if anything fails, so it can be wired into CI.
 */

use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\Plugin;
use Symfony\Component\Yaml\Yaml;

$base = getenv('CRAFT_BASE_PATH') ?: getcwd();

if (!is_file($base . '/bootstrap.php')) {
    fwrite(STDERR, "Set CRAFT_BASE_PATH to a Craft project with Archive installed.\n");
    exit(1);
}

if (!defined('CRAFT_BASE_PATH')) {
    define('CRAFT_BASE_PATH', $base);
}

require $base . '/vendor/autoload.php';
require $base . '/bootstrap.php';
require $base . '/vendor/craftcms/cms/bootstrap/console.php';

$plugin = Plugin::getInstance();

if ($plugin === null) {
    fwrite(STDERR, "Archive isn’t installed in this project.\n");
    exit(1);
}

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;

    echo ($ok ? "  ok    " : "  FAIL  ") . $label . "\n";

    if (!$ok) {
        $failures++;
    }
}

function entry(string $zip, string $name): ?string
{
    $archive = new ZipArchive();

    if ($archive->open($zip) !== true) {
        return null;
    }

    $contents = $archive->getFromName($name);
    $archive->close();

    return $contents === false ? null : $contents;
}

// --- export once per format ---------------------------------------------------------
$paths = [];
$prefix = 'archive-integration-' . bin2hex(random_bytes(4));

foreach (array_keys($plugin->writers->all()) as $format) {
    $config = ExportConfig::fromSettings();
    $config->types = array_keys($plugin->collectors->all());
    $config->format = $format;
    $config->name = "$prefix-$format";
    $config->includeSchema = true;
    $config->includeDisabled = true;

    $bundle = $plugin->export->run($config);

    check("$format export completed", $bundle->status === 'completed');
    $paths[$format] = $plugin->bundles->path($bundle);
}

// --- JSON is the reference, and is assembled by hand, so validity comes first --------
$json = json_decode((string)entry($paths['json'], 'data/archive.json'), true);
check('json parses', is_array($json));
check('json has meta, schema and records', isset($json['meta'], $json['schema'], $json['records']));

$reference = $json['records'] ?? [];
$totalRecords = array_sum(array_map('count', $reference));
check('json contains records', $totalRecords > 0);
echo '  counts: ' . json_encode(array_map('count', $reference)) . "\n";

// --- NDJSON --------------------------------------------------------------------------
$nd = [];
$metaLines = 0;

foreach (explode("\n", trim((string)entry($paths['ndjson'], 'data/archive.ndjson'))) as $line) {
    if ($line === '') {
        continue;
    }

    $decoded = json_decode($line, true);

    if (!is_array($decoded)) {
        check('every ndjson line parses', false);
        continue;
    }

    if ($decoded['_type'] === 'record') {
        $nd[$decoded['recordType']][] = $decoded['record'];
    } else {
        $metaLines++;
    }
}

check('ndjson has meta and schema lines', $metaLines === 2);
check('ndjson records match json', $nd == array_filter($reference, fn($r) => $r !== []));

// --- YAML ----------------------------------------------------------------------------
try {
    $yaml = Yaml::parse((string)entry($paths['yaml'], 'data/archive.yaml'));
    check('yaml parses', is_array($yaml));
    check('yaml records match json', ($yaml['records'] ?? null) == $reference);
    check('yaml schema matches json', ($yaml['schema'] ?? null) == ($json['schema'] ?? null));
} catch (Throwable $e) {
    check('yaml parses (' . $e->getMessage() . ')', false);
}

// --- XML -----------------------------------------------------------------------------
$dom = new DOMDocument();
check('xml is well-formed', $dom->loadXML((string)entry($paths['xml'], 'data/archive.xml')));

$xpath = new DOMXPath($dom);
check('xml record count matches json', $xpath->query('/archive/records/record')->length === $totalRecords);

foreach ($reference as $type => $records) {
    if ($records === []) {
        continue;
    }

    check(
        "xml has $type records",
        $xpath->query("/archive/records/record[@type='$type']")->length === count($records)
    );
}

// --- CSV -----------------------------------------------------------------------------
foreach ($reference as $type => $records) {
    $csv = entry($paths['csv'], "data/csv/$type.csv");

    if ($records === []) {
        check("csv omits the empty $type file", $csv === null);
        continue;
    }

    if ($csv === null) {
        check("csv has $type.csv", false);
        continue;
    }

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $csv);
    rewind($handle);

    $header = fgetcsv($handle, 0, ',', '"', '');
    $rows = [];

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    check("csv $type.csv has one row per record", count($rows) === count($records));
    check(
        "csv $type.csv rows all match the header width",
        count(array_filter($rows, fn($r) => count($r) !== count($header))) === 0
    );
}

// --- every bundle should carry the same asset files ----------------------------------
$manifest = json_decode((string)entry($paths['json'], 'manifest.json'), true);
$files = $manifest['assets']['files'] ?? [];

foreach ($paths as $format => $path) {
    $missing = array_filter($files, fn(string $file) => entry($path, $file) === null);
    check("$format bundle contains its asset files", $missing === []);
}

// --- clean up -------------------------------------------------------------------------
foreach ($plugin->bundles->getAll(500) as $bundle) {
    if (str_starts_with($bundle->name, $prefix)) {
        $plugin->bundles->delete($bundle);
    }
}

echo "\n" . ($failures === 0 ? "PASS\n" : "$failures check(s) failed\n");
exit($failures === 0 ? 0 : 1);
