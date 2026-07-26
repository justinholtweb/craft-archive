<?php

/**
 * Bootstrap for the unit suite.
 *
 * These tests run against plain PHP — no Craft application, no database. Everything they
 * cover is deliberately pure: flattening, JSON-safe conversion, and the record store's
 * file handling. Anything that needs a live Craft is exercised by the integration script
 * documented in tests/README.md instead.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
