<?php

declare(strict_types=1);

namespace App\Conflation\Io;

/**
 * What describes a merged data set, as a source's definition describes the
 * data set it publishes.
 */
final readonly class MergedDataSet
{
    /**
     * @param string       $id         the source id stamped on every merged entity
     * @param string       $model      the result model every merged entity is built in
     * @param string       $contextUrl the result model's JSON-LD context
     * @param string|null  $licence    null while the licence is unsettled
     * @param list<string> $inputs     the source ids of the data sets the merge reads
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $model,
        public string $contextUrl,
        public ?string $licence,
        public array $inputs,
    ) {
    }

    /**
     * The merged data set as the list of data sets gives it, under the keys
     * a source's definition uses, plus the data sets it is merged from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'model' => $this->model,
            'context_url' => $this->contextUrl,
            'licence' => $this->licence,
            'inputs' => $this->inputs,
        ];
    }
}
