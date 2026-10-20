# 004: Conflation pipeline

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

003 sets the goal that each real-world thing is published once, carrying the best of what every data set says about
it. Today a thing described by several data sets is published once per data set, with nothing relating the copies.

Combining these copies into one is called conflation. It means deciding which records describe the same thing, which
value to keep when they give different values for the same detail, and which facts to add from data sets about
something else at the same place.

One rule will not suit every kind of thing. Some stand close together and some far apart, some are mapped as points and
some as areas, and a data set can be reliable for some details and not for others.

This ADR serves to decide how conflation is structured, how it is set up for each kind of thing, and how the result is
published.

## Decision

We will conflate in five steps and publish the result as a new, separate data set. We keep the original data sets as
they were imported, so conflation can always be run again from scratch. This could prove quite useful when a conflation
rule changes, a data set is updated at its source or an error (due to conflation) is discovered.

Each kind of thing, such as toilets or benches, is conflated on its own. Each step has a standard way of working, and a
kind of thing can use it or choose another way that suits it better.

Conflation runs after every data set it reads has been imported without errors. If a step gives a result that breaks the
limits set for it, the run stops and nothing is published. What happens when a record disappears from its data set is
decided together with the lifecycle of records.

**1. Fetch** reads the records to conflate from every data set that has records of the kind of thing.

**2. Match** decides which records describe the same thing. By default, records that lie close to each other are taken
to be the same thing. The approach to matching can vary based on the nature of the kind.

**3. Resolve conflicts** chooses which value to keep when matched records give different values for the same detail,
such as opening hours. It has no default: for each kind of thing we decide which data sets to trust for which details.
Until that is decided, a value the data sets disagree on is left out, and the disagreement is recorded.

**4. Augment** adds facts from data sets about something else at the same place, such as the district a bench stands in.
By default, an augmentation has to be configured manually.

**5. Create** publishes one merged entity that uses the same data model as the records it was built from as 003 requires.
Its identifier is taken from one of the records by a fixed rule. Entities that a new run no longer produces are deleted.

## Consequences

### Positive

- Users can get one entity per real-world thing from the merged data set, or keep reading the data set they trust.
- The original data sets stay as their publishers made them, so a merged entity can be checked against its records.
- A wrong merge is fixed by changing a rule and running the merge again, without repairing any data set.
- A new kind of thing mostly needs settings, and code only where the standard rules do not suit it.

### Negative

- Matching is automatic, so it is possible that some merged entities will join things that are different, or miss things
  that are the same.
- A merged entity's identifier can change between runs, so users who store it may lose track of the thing.
- The merged data set is only as current as the last run, and records removed from their data set stay in it until the
  lifecycle of records is decided.
