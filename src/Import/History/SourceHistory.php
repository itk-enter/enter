<?php

declare(strict_types=1);

namespace App\Import\History;

/**
 * The import history of one source: the last import, the last successful
 * import, and the imports of a period of full days.
 *
 * The data of the source changed when the payload hash of a successful
 * import differs from the hash of the successful import before it. The
 * comparison uses only the imports of the period. Thus the first successful
 * import of the period is not a change.
 *
 * The added entities of a full load do not count in added() and $days. A
 * full load adds all the entities of the source, and the large number hides
 * the normal changes. The removed entities always count.
 *
 * Example: three imports with the payload hashes "a", "b" and "b" give one
 * change of the data, at the second import.
 */
final readonly class SourceHistory
{
    /**
     * The number of imports that events() gives if no limit is given.
     */
    public const int EVENTS = 10;

    /**
     * @var list<HistoryDay> the imports of each day of the period, oldest
     *                       first. A day without imports is also in the list.
     */
    public array $days;

    /**
     * @var array<int, true> the ids of the imports that changed the data
     */
    private array $dataChanges;

    /**
     * @param ImportSummary       $lastImport  the last import of the source, also if it is older than the period
     * @param ImportSummary|null  $lastSuccess the last successful import of the source, also if it is older than the period
     * @param list<ImportSummary> $imports     the imports that started in the period, oldest first
     * @param \DateTimeImmutable  $since       the start of the first day of the period
     */
    public function __construct(
        public string $sourceId,
        public ImportSummary $lastImport,
        public ?ImportSummary $lastSuccess,
        public array $imports,
        public \DateTimeImmutable $since,
        public \DateTimeImmutable $now,
    ) {
        $dataChanges = [];
        $hash = null;
        foreach ($imports as $import) {
            if (!$import->isSucceeded()) {
                continue;
            }
            if (null !== $hash && $hash !== $import->payloadHash) {
                $dataChanges[$import->id] = true;
            }
            $hash = $import->payloadHash;
        }
        $this->dataChanges = $dataChanges;

        $days = [];
        for ($date = $since; $date <= $now; $date = $date->modify('+1 day')) {
            $days[$date->format('Y-m-d')] = new HistoryDay($date);
        }
        foreach ($imports as $import) {
            $key = $import->startedAt->setTimezone($since->getTimezone())->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key] = $days[$key]->with($import);
            }
        }
        $this->days = array_values($days);
    }

    /**
     * The number of imports in the period that failed.
     */
    public function failed(): int
    {
        return \count(array_filter($this->imports, static fn (ImportSummary $import): bool => $import->isFailed()));
    }

    /**
     * The median time between the starts of two imports in the period, in
     * seconds. Null if the period has fewer than two imports.
     */
    public function importInterval(): ?int
    {
        $intervals = [];
        for ($i = 1, $count = \count($this->imports); $i < $count; ++$i) {
            $intervals[] = $this->imports[$i]->startedAt->getTimestamp() - $this->imports[$i - 1]->startedAt->getTimestamp();
        }

        return self::median($intervals);
    }

    /**
     * The time since the start of the last import, in seconds.
     */
    public function sinceLastImport(): int
    {
        return $this->now->getTimestamp() - $this->lastImport->startedAt->getTimestamp();
    }

    /**
     * Tells if the import changed the data of the source.
     */
    public function changedData(ImportSummary $import): bool
    {
        return isset($this->dataChanges[$import->id]);
    }

    /**
     * The number of imports in the period that changed the data.
     */
    public function dataChanges(): int
    {
        return \count($this->dataChanges);
    }

    /**
     * The last import in the period that changed the data, or null.
     */
    public function lastDataChange(): ?ImportSummary
    {
        foreach (array_reverse($this->imports) as $import) {
            if ($this->changedData($import)) {
                return $import;
            }
        }

        return null;
    }

    /**
     * The number of entities that the imports of the period added, without
     * full loads.
     */
    public function added(): int
    {
        return array_sum(array_map(static fn (HistoryDay $day): int => $day->added, $this->days));
    }

    /**
     * The number of entities that the imports of the period removed.
     */
    public function removed(): int
    {
        return array_sum(array_map(static fn (HistoryDay $day): int => $day->removed, $this->days));
    }

    /**
     * The largest number of added or removed entities on one day of the
     * period. A chart of the days uses it as its scale.
     */
    public function busiestDay(): int
    {
        return max(0, ...array_map(static fn (HistoryDay $day): int => max($day->added, $day->removed), $this->days));
    }

    /**
     * The last imports in the period that failed, changed the data, or added
     * or removed entities. The last import is first.
     *
     * @param int $limit the maximum number of imports
     *
     * @return list<ImportSummary>
     */
    public function events(int $limit = self::EVENTS): array
    {
        $events = array_filter(
            array_reverse($this->imports),
            fn (ImportSummary $import): bool => $import->isFailed() || $import->changedEntities() || $this->changedData($import),
        );

        return \array_slice(array_values($events), 0, $limit);
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): ?int
    {
        if ([] === $values) {
            return null;
        }

        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? intdiv($values[$middle - 1] + $values[$middle], 2) : $values[$middle];
    }
}
