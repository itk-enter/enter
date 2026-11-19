<?php

declare(strict_types=1);

namespace App\Source;

/**
 * The configuration of a source.
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
     * @param string                $dataset       the ID of the data set that contains the source
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
        public string $dataset,
        public string $datasetTitle,
    ) {
    }

    /**
     * Reads the definition from the attribute of a source class.
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
     */
    public function accessUrlWithQuery(): string
    {
        $query = http_build_query($this->accessUrlQuery(), encoding_type: \PHP_QUERY_RFC3986);

        return '' === $query ? $this->accessUrlBase() : $this->accessUrlBase().'?'.$query;
    }

    /**
     * The URL of the page that describes the data model of the source, or
     * null if the context is not in a repository of data models on GitHub.
     */
    public function modelUrl(): ?string
    {
        return self::modelUrlOf($this->contextUrl, $this->model);
    }

    /**
     * The URL of the page that describes a data model, made from the URL of
     * its JSON-LD context.
     *
     * A repository of data models, as Smart Data Models has them, keeps the
     * context of a domain next to a folder for each model. The page is that
     * folder on GitHub, which shows the README of the model. The function
     * returns null if the context is not a raw file in a GitHub repository.
     */
    public static function modelUrlOf(string $contextUrl, string $model): ?string
    {
        if (1 !== preg_match('#^https://raw\.githubusercontent\.com/([^/]+/[^/]+)/(.+)/context\.jsonld$#', $contextUrl, $matches)) {
            return null;
        }

        return \sprintf('https://github.com/%s/tree/%s/%s', $matches[1], $matches[2], $model);
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
                'id' => $this->dataset,
                'title' => $this->datasetTitle,
            ],
        ];
    }
}
