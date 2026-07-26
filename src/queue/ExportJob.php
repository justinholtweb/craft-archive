<?php

namespace justinholtweb\archive\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\Plugin;

/**
 * Runs an export on the queue, so a big site isn't at the mercy of a web request timeout.
 */
class ExportJob extends BaseJob
{
    /**
     * @var array The export config, as an array — queue payloads have to be serializable.
     */
    public array $config = [];

    /**
     * An estimate of how many records this run will produce, used to show a sensible
     * progress bar. Zero means Archive couldn't estimate it, and progress stays
     * indeterminate.
     */
    public int $estimate = 0;

    public function execute($queue): void
    {
        $config = new ExportConfig();
        $config->setAttributes($this->config, false);

        $estimate = $this->estimate;

        Plugin::getInstance()->export->run($config, function(string $type, int $total) use ($queue, $estimate) {
            if ($estimate > 0) {
                $this->setProgress(
                    $queue,
                    min(1, $total / $estimate),
                    Craft::t('archive', '{total} of about {estimate} records', [
                        'total' => $total,
                        'estimate' => $estimate,
                    ])
                );
            } else {
                $this->setProgress($queue, 0, Craft::t('archive', '{total} records', ['total' => $total]));
            }
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('archive', 'Building an Archive bundle');
    }
}
