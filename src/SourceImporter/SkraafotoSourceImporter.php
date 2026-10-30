<?php

namespace App\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Geo\Wgs84Transformer;
use App\Source\DataType;
use App\Source\SourceInterface;
use App\SourceReader\SkraafotoReader;
use App\SourceReader\SourceReaderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Imports the oblique photos that SkraafotoReader finds for the merged
 * entities.
 */
class SkraafotoSourceImporter extends AbstractSourceImporter
{
    /**
     * @param list<string> $contextUrls
     */
    public function __construct(
        #[Autowire(service: SkraafotoReader::class)]
        SourceReaderInterface $reader,
        NgsiLdBroker $broker,
        Wgs84Transformer $transformer,
        #[Autowire(env: 'json:APP_NGSI_CONTEXT_URLS')]
        array $contextUrls,
        LoggerInterface $logger,
    ) {
        parent::__construct($reader, $broker, $transformer, $contextUrls, $logger);
    }

    public function supports(SourceInterface $source): bool
    {
        return DataType::Skraafoto === $source->definition->dataType;
    }

    protected function extractItems(iterable $data, SourceInterface $source): array
    {
        return \is_array($data) ? array_values($data) : iterator_to_array($data, false);
    }
}
