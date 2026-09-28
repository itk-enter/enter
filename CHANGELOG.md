# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

* [PR-43](https://github.com/itk-enter/enter/pull/43)
  Added a findToilet source importer
* [PR-42](https://github.com/itk-enter/enter/pull/42)
  Added ADR for decisions on vocabs outside smart data models
* [PR-35](https://github.com/itk-dev/enter/pull/35)
  Removed DDEV setup
* [PR-31](https://github.com/itk-dev/enter/pull/31)
  Made test sources independent from real sources
* [PR-28](https://github.com/itk-dev/enter/pull/28)
  Aligned the test setup with the new importer approach
* [PR-24](https://github.com/itk-dev/enter/pull/24)
  Refactored sources and releated services
* [PR-25](https://github.com/itk-dev/enter/pull/25)
  Add housing for custom attributes
* [PR-10](https://github.com/itk-dev/enter/pull/10)
  Added test data setup
* [PR-19](https://github.com/itk-dev/enter/pull/19)
  Declare source metadata in an `#[AsDataSource]` attribute on the source class
* [PR-12](https://github.com/itk-dev/enter/pull/12)
  Add source definition as class
* [PR-9](https://github.com/itk-dev/enter/pull/9)
  Refactored source import
* [PR-7](https://github.com/itk-dev/enter/pull/7)
  Refactored source definition
* [#5](https://github.com/itk-dev/enter/pull/5)
  * Added osm-handicap-parking.
* [#3](https://github.com/itk-dev/enter/pull/3)
  * Import command that reads a geospatial feed, reprojects it to WGS84 and upserts it to an NGSI-LD broker.
  * A committed record per data set — feed, CRS, model, DCAT-AP metadata.
  * An extension point for adding data sets, a test suite, and architecture decision records.
  * Source manifest validated against a Symfony config tree and read during container warm-up, so a malformed
    entry fails the build rather than the one import that selects it.

[Unreleased]: https://github.com/itk-dev/enter
