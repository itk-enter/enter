# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

* [PR-121](https://github.com/itk-enter/enter/pull/121)
  Fixed the /test map, which hid every test data set
* [PR-108](https://github.com/itk-enter/enter/pull/108)
  Added Geos extension to PHP
* [PR-110](https://github.com/itk-enter/enter/pull/110)
  Added the merge of disabled parking bays and on-street sites into ParkingSpots
* [PR-106](https://github.com/itk-enter/enter/pull/106)
  Loosen rules set by merge-by-location and introduce restated records.
* [PR-103](https://github.com/itk-enter/enter/pull/103)
  Typed a one-bay OSM parking area as OnStreetParking or OffStreetParking, not ParkingSpot
* [PR-109](https://github.com/itk-enter/enter/pull/109)
  Applied the coding practices and the ASD-STE100 rules in CLAUDE.md to the code
* [PR-99](https://github.com/itk-enter/enter/pull/99)
  * Added event listener for SourcesImportedEvent
* [PR-113](https://github.com/itk-enter/enter/pull/113)
  Hid the test data sets without entities on the /test map
* [PR-111](https://github.com/itk-enter/enter/pull/111)
  Upgraded the Scorpio database to PostGIS 18 and added the Woodpecker deploy to staging
* [PR-98](https://github.com/itk-enter/enter/pull/98)
  Fixed merged data sets breaking the /test map
* [PR-90](https://github.com/itk-enter/enter/pull/90)
  Added conflation diagram svgs and markdown.
* [PR-94](https://github.com/itk-enter/enter/pull/94)
  Consolidated ADR 007.
* [PR-93](https://github.com/itk-enter/enter/pull/93)
  Consolidated ADR 006.
* [PR-91](https://github.com/itk-enter/enter/pull/91)
  Consolidated ADR 005.
* [PR-85](https://github.com/itk-enter/enter/pull/85)
  Consolidated ADR 004.
* [PR-83](https://github.com/itk-enter/enter/pull/83)
  Grouped the conflation classes into Data, Spatial, Pipeline and Io
* [PR-82](https://github.com/itk-enter/enter/pull/82)
  Listed the merged data sets on /sources, in sources.json and on the map
* [PR-81](https://github.com/itk-enter/enter/pull/81)
  Added the public toilet merge and MajorityWins, an optional conflict-resolution approach
* [PR-80](https://github.com/itk-enter/enter/pull/80)
  Added creating and publishing merged entities, and app:conflation:run
* [PR-79](https://github.com/itk-enter/enter/pull/79)
  Added the conflation fetch step, reading records from the broker
* [PR-89](https://github.com/itk-enter/enter/pull/89)
  Added WithinAreaOrRadius, the default augmentation
* [PR-88](https://github.com/itk-enter/enter/pull/88)
  Added SourceAuthority, an optional conflict-resolution approach
* [PR-87](https://github.com/itk-enter/enter/pull/87)
  Added the default merge by location for conflation, matching points and areas one-to-one, closest first
* [PR-86](https://github.com/itk-enter/enter/pull/86)
  Added the core of the conflation pipeline: the Conflation attribute, the abstract class of each step, and the
  checks between steps
* [PR-68](https://github.com/itk-enter/enter/pull/68)
  Added app:source:import-all, and a SourcesImportedEvent dispatched when a
  run over several sources is done
* [PR-73](https://github.com/itk-enter/enter/pull/73)
  Added the default matching engine for merging by location
* [PR-72](https://github.com/itk-enter/enter/pull/72)
  Added ADR 007 on augmenting merged entities in conflation
* [PR-71](https://github.com/itk-enter/enter/pull/71)
  Added ADR 006 on resolving conflicts in conflation
* [PR-70](https://github.com/itk-enter/enter/pull/70)
  Added ADR 005 on merging conflation records by location
* [PR-69](https://github.com/itk-enter/enter/pull/69)
  Added ADR 004 on the conflation pipeline, and asked ADRs for plain wording and examples
* [PR-60](https://github.com/itk-enter/enter/pull/60)
  * Split the OSM and MTM handicap parking sources into one source per
    model, sharing a data set.
  * Deleted entities a source no longer yields.
  * Cached source responses.
  * Reported entities the broker rejects.
  * Added the kind of facility to OSM off-street parking.
  * Grouped the `/test` map by model, coloured by data set.
* [PR-59](https://github.com/itk-enter/enter/pull/59)
  Added the osm-bench source for benches from OpenStreetMap, published under
  the Bench data model
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
