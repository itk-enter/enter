<?php

declare(strict_types=1);

namespace App\Source;

/**
 * The configuration of a source.
 *
 * @see AsDataSource
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
readonly class Definition
{
    /**
     * @param string|array{
     *      url: string,
     *      query: array<string, mixed>
     * } $accessUrl
     * @param array<string, string> $omittedFields
     * @param ?string               $dataset       the data set a source shares with others reading the same feed, one per model; null when the source is the whole data set
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $publisher,
        public string $contact,
        public string $landingPage,
        public string|array $accessUrl,
        public DataType $dataType,
        public string $mediaType,
        public string $crs,
        public string $model,
        public string $contextUrl,
        public string $updateFrequency,
        public ?string $licence,
        public array $omittedFields,
        public ?string $dataset = null,
        public ?string $datasetTitle = null,
    ) {
        if ((null === $dataset) !== (null === $datasetTitle)) {
            throw new \InvalidArgumentException(sprintf('Source %s must declare both a dataset and its title, or neither.', $id));
        }
    }

    /**
     * The definition a source class declares.
     *
     * Reading it takes no instance, so the metadata of every data set is
     * available without building the sources and their collaborators.
     *
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    public static function of(string $class): self
    {
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Definition::class, flags: \ReflectionAttribute::IS_INSTANCEOF)[0]
            ?? throw new \LogicException(sprintf('Source %s declares no #[%s] attribute.', $class, Definition::class));

        return $attribute->newInstance();
    }

    /**
     * The URL to request, without the query parameters.
     */
    public function accessUrlBase(): string
    {
        return is_array($this->accessUrl) ? $this->accessUrl['url'] : $this->accessUrl;
    }

    /**
     * The query parameters to send with the request.
     *
     * @return array<string, mixed>
     */
    public function accessUrlQuery(): array
    {
        return is_array($this->accessUrl) ? $this->accessUrl['query'] : [];
    }

    /**
     * The URL to request, with its query parameters, as one URI.
     *
     * An entity's source is a single URI, so the array form of the access
     * URL cannot be published as it is.
     */
    public function accessUrlWithQuery(): string
    {
        $query = http_build_query($this->accessUrlQuery(), encoding_type: \PHP_QUERY_RFC3986);

        return '' === $query ? $this->accessUrlBase() : $this->accessUrlBase().'?'.$query;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'publisher' => $this->publisher,
            'contact' => $this->contact,
            'landing_page' => $this->landingPage,
            'access_url' => $this->accessUrl,
            'data_type' => $this->dataType,
            'media_type' => $this->mediaType,
            'crs' => $this->crs,
            'model' => $this->model,
            'context_url' => $this->contextUrl,
            'update_frequency' => $this->updateFrequency,
            'licence' => $this->licence,
            'omitted_fields' => $this->omittedFields,
            'dataset' => [
                'id' => $this->dataset ?? $this->id,
                'title' => $this->datasetTitle ?? $this->title,
            ],
        ];
    }
}
