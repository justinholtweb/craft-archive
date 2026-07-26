<?php

namespace justinholtweb\archive\writers;

use craft\helpers\FileHelper;
use justinholtweb\archive\models\ExportContext;
use RuntimeException;
use yii\base\BaseObject;

/**
 * Shared plumbing for writers: the document shape and safe file writing.
 */
abstract class BaseWriter extends BaseObject implements WriterInterface
{
    /**
     * The complete document a writer is asked to represent: metadata, optional schema,
     * and the records themselves.
     */
    protected function document(ExportContext $context): array
    {
        $document = ['meta' => $context->meta ?? []];

        if ($context->schema) {
            $document['schema'] = $context->schema;
        }

        $document['records'] = $context->records;

        return $document;
    }

    /**
     * Writes a file into the staging directory, creating parent directories as needed.
     *
     * @param string $relativePath Bundle-relative, e.g. 'data/archive.json'.
     * @return string The relative path, for chaining into the manifest.
     */
    protected function put(string $stagingDir, string $relativePath, string $contents): string
    {
        $target = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        FileHelper::createDirectory(dirname($target));

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException("Couldn’t write $relativePath into the bundle.");
        }

        return $relativePath;
    }
}
