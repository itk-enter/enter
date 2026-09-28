# 008: Vocabulary — a general-purpose fallback where no Smart Data Model exists

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde                                             |
| **Date**           | 2026-09-23                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

005 adopted Smart Data Models as this application's vocabulary, but its
coverage is uneven: some domains have no model in the catalogue at all,
which 005 did not address — it evaluated a general-purpose web vocabulary
only as an alternative for domains Smart Data Models does cover, and rejected
it there for lacking domain terms a covered domain already has. This ADR
serves to decide which vocabulary to publish under when no Smart Data Model
exists for a domain at all.

### Drivers

- **Functional:** types and attributes interpretable without this project's
  own documentation, expressible as a JSON-LD context a broker can resolve,
  adequate for a place-like entity with a name, a description, an address and
  a point geometry.
- **Non-functional:** reversible, with no invented private vocabulary to
  govern.

### Options Considered

1. **A private vocabulary for the gap domain.** Exact fit, but nobody else
   speaks it and governance and documentation stay with this project
   indefinitely — the same reasoning 005 already rejected this option under.
2. **A general-purpose web vocabulary.** Widely recognised and stably
   governed, adequate for names, addresses and descriptions, but with no
   NGSI-LD conventions for geometry or relationships and no domain terms —
   the same limitation 005 noted, but one that does not bite for an entity
   with no relationships and no required sub-hierarchy.
3. **Wait for Smart Data Models to add coverage.** Keeps the single
   vocabulary policy intact, but leaves the domain unpublished for as long as
   coverage takes to appear, with no committed timeline.

## Decision

Adopt a **general-purpose web vocabulary** for a domain Smart Data Models does
not cover, restricted to entities with no relationships and no required
sub-hierarchy. If Smart Data Models later adds coverage for the domain,
migrate to it under the same reasoning 005 already gives for a model change.

- The gap is specific to domains absent from the catalogue entirely, not a
  reopening of 005's general preference for Smart Data Models.
- Restricting the fallback to relationship-free, hierarchy-free entities
  keeps the one limitation this vocabulary carries — no NGSI-LD conventions
  for geometry or relationships — from ever applying to what is published
  under it.
- Waiting for coverage has no committed timeline and blocks publication
  indefinitely; a private vocabulary repeats a cost 005 already rejected.

## Consequences

### Positive

- A domain otherwise unpublishable under this project's vocabulary policy can
  be published without inventing a schema.
- Consumers already familiar with the general vocabulary need no additional
  documentation for it.

### Negative / Trade-offs

- No NGSI-LD-native conventions for geometry or relationships — the
  restriction to relationship-free, hierarchy-free entities keeps this from
  mattering in practice, but means the fallback does not extend to a domain
  that later needs either.
- Model choice is embedded in entity identifiers, so a later migration to a
  Smart Data Model that gains coverage means deleting and re-publishing rather
  than updating in place — the same cost 005 already documents for any model
  change.
