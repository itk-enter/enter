# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

* [PR-41](https://github.com/itk-enter/enter/pull/41)
  * Replaced the Septima widget on `/test` with a MapLibre developer map
  * Drew each test source from the broker, toggled on and off per source
  * Served Bootstrap and MapLibre through the importmap instead of CDN tags
  * Marked the generated `config/reference.php` and `symfony.lock` so GitHub collapses them in diffs
* [PR-40](https://github.com/itk-enter/enter/pull/40)
  Stamped every entity with the id of its source, and published the list of sources on `/sources` and `/sources.json`
* [PR-57](https://github.com/itk-enter/enter/pull/57)
  Used nginx as proxy for Scorpio
* [PR-56](https://github.com/itk-enter/enter/pull/56)
  Mapped the toilet sources onto the PublicToilet data model, and added
  the osm-public-toilet and findtoilet-public-toilet sources
* [PR-50](https://github.com/itk-dev/enter/pull/50)
  Consolidated the seven draft ADRs into three finalized ones, and added
  a adr file for future planned ADRs.
* [PR-51](https://github.com/itk-enter/enter/pull/51)
  Removed ADR 008 on vocabulary fallback outside Smart Data Models
* [PR-46](https://github.com/itk-dev/enter/pull/46)
  Added mtm_spatialmaps-toilet-city source
* [PR-45](https://github.com/itk-dev/enter/pull/45)
  Update public toilet source with mtm_spatialmaps-toilet-other
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
