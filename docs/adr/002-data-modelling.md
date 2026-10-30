# 002: Data modeling

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Jeppe Krogh                                            |
| **Date**           | 2026-08-31                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Accepted                                               |

## Context

The selected context broker carries attributes and references a vocabulary,
but it does not define the data model. We have a great focus on interoperability,
so selecting a standardized model for data, seems to be the way to go.

## Decision

A few different options seem to be available, but since Smart Data Models
is the de-facto vocabulary of the NGSI-LD ecosystem, we choose to go with that.
Which model a given data set uses is a decision for that data set, and has
to be documented.

Coverage is uneven, so some data sets will have no existing model to fit.
Every data set is published under a Smart Data Model: an existing one where
it fits, otherwise a new model built on the Smart Data Models standard. A new
model reuses the common properties, GeoJSON location, postal address, units
and timestamp conventions as-is, and adds only the attributes specific to the
data set, so that it can be contributed to the program later.

Where a type has to come from a vocabulary other than Smart Data Models, only
the type comes across. Its geometry, addresses, units and timestamps still
follow the Smart Data Models conventions, so that a single query still reaches
the whole estate.

## Consequences

### Easier

- Types and attributes resolve to shared global identifiers that consumers may
  already have code for.
- A later data set is likely covered by a model already, so onboarding it does
 not start with designing a vocabulary.
- What we publish stands on its own, with nothing invented to keep in sync.
- A missing model does not stall a data set, because using it and submitting it
 are separate steps.
- Contributing is cheap. The program is built for quick turnaround, so we submit
 work we had to do anyway.

### Harder

- The model is part of the entity identifier, so changing our minds later comes
  with some work.
- Versioning is loose, so a model can change without an obvious signal.
- The catalogue is uneven, so each data set costs an assessment before it costs a
  mapping: the model may be missing, shaped by an older paradigm, or simply a poor
  fit for the source.
- The linked-data annotations in the schemas are documentation, not enforcement,
  so validation stays ours to run.
