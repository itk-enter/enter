# 003: Modeling approach

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Jeppe Krogh                                            |
| **Date**           | 2026-09-22                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Pending                                                |

## Context

002 chooses Smart Data Models as the vocabulary and leaves the choice of model
to each data set. The first few data sets showed that a data set is not always one
kind of thing and two feeds can describe the same places at different
granularity. Fitting such a data set to a single model means either inventing
detail the data does not have or throwing away detail it does.

The purpose of publishing several sources about the same things is to conflate
them into one picture, and how records are modeled decides whether that merge
is possible later.

## Decision

We type each record by what it describes, not by the data set it comes from. A
data set may therefore produce entities of several models.

Several models for one data set is a cost we take only where the data requires
it, that is where a sources describe the same things or different subjects at
different granularity.

Merging should result in one entity per real-world thing, of the model that
describes it, carrying the best of what every source says about it:

- the most precise geometry
- the union of attributes and aggregated facts.

Merging changes how much we know about
a thing, not what kind of thing it is, so merged entities use the same models
as the records they are built from.

How merged entities are produced, identified and related to their sources is
decided separately. see ADR @TODO

## Consequences

### Easier

- The granularity of a record survives into its type, so records about the
  same place at different levels can be related instead of flattened.
- Nothing is invented to make a record fit, and nothing is dropped because a
  data set was given one model.
- Merging adds no modeling work, since the merged result is of models already
  in use.

### Harder

- An adapter has to sort records into kinds before mapping them, and a data
  set can no longer be described by one model.
- Until merging is in place, a place described by several sources appears as
  several entities, and units and containers from different sources are
  unrelated.
- Mandatory attributes we have no data for are carried with the schema's
  placeholder value, present only because the schema requires them.
