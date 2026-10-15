# Architecture Decision Records

This directory contains Architecture Decision Records (ADRs) for this project.

See [adr.github.io](https://adr.github.io/) for background on the format.

| Number                                       | Title                           | Status   | Date       |
|----------------------------------------------|---------------------------------|----------|------------|
| [001](001-publication-mechanism.md)          | Publication mechanism           | Accepted | 2026-08-24 |
| [002](002-data-modelling.md)                 | Data modeling                   | Accepted | 2026-08-31 |
| [003](003-modeling-approach.md)              | Modeling approach               | Pending  | 2026-09-22 |
| [004](004-conflation-pipeline.md)            | Conflation pipeline             | Draft    | 2026-10-13 |
| [005](005-conflation-merge-by-location.md)   | Conflation, merge by location   | Draft    | 2026-10-13 |
| [006](006-conflation-conflict-resolution.md) | Conflation, conflict resolution | Draft    | 2026-10-13 |
| [007](007-conflation-augmentation.md)        | Conflation, augmentation        | Draft    | 2026-10-13 |

Numbering follows dependency order: each ADR relies only on lower-numbered
ones. Dates therefore need not run in the same order as numbers.

All ADRs state general policy and name no data set. Concrete per-data-set
facts are declared on the source classes themselves, alongside the mappings
they describe.
