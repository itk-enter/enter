# 004: Conflation — a pipeline of replaceable steps into a data set of its own

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

003 aims for one entity per real-world thing, carrying the best of what every data set says about it. Today a thing
described by several data sets is published once per data set, with nothing relating the copies.

For example, a municipal register and a crowd-sourced map can both list the public toilet in a park. Each gives it an
identifier of its own and a position a few metres from the other's, and each states some attributes the other lacks.
A consumer asking for the toilets in the park gets two, and has to work out for themselves that they are one.

Combining such records into one entity is called conflation. It involves several separate questions:

- **Which records describe the same thing.** Records of one thing from different data sets rarely share an
  identifier, so they have to be related by where they are and what they are.
- **What to do when they disagree.** Two data sets can state different values for the same attribute.
- **What to do with data about something else.** Some data sets do not describe the things being merged, but add
  facts about them, such as what covers the ground they stand on.

Each of these is decided separately. They have in common that the kinds of things published differ: some stand
densely and some sparsely, some are described as points and some as areas, and different data sets are trustworthy
for different attributes. A rule that suits one kind will not suit every kind.

How conflation is structured, how a merge for a kind is declared and customized, and how its result is published and
identified are undecided.

This ADR serves to decide the overall structure of conflation: its steps, how a merge is declared and customized,
and how the merged result is identified and published.

### Drivers

- **Functional:** one entity per real-world thing, for every kind published; a default that suits most kinds with
  configuration alone; a way to replace or extend one step for a kind the default does not suit; a result that can be
  traced back to its inputs; inputs left intact.
- **Non-functional:** repeatable, so the same inputs give the same result; each step testable on its own; adding a
  kind costs configuration, and code only where it needs rules of its own; a customization written for one kind can
  be reused by another.

### Options Considered

#### Where the merged result is published

**Merging into one of the input data sets.** The best-described data set is enriched with what the others say, and
the others' copies are removed. Consumers see one entity per thing without a new data set, but the chosen data set no
longer says what its publisher said, a wrong merge has to be repaired in it, and no single data set is best for every
kind.

**Merging when a consumer asks.** Data sets stay as published, and an API or the consumer merges them per request.
Nothing extra is stored, but every consumer pays for the merge on every request, results can differ between
consumers, and the identifiers of merged things are not stable.

**A data set of its own.** The merged entities are published as a further data set beside the inputs. Every input
stays intact and attributable, and a wrong merge is fixed by running it again, but each thing is published once more.

#### How a merge is customized

**Configuration only.** Every merge is expressed in configuration and read in one place. Every rule a kind needs has
to become a configuration option, and cases such as matching points against areas would grow the configuration into
a language of its own.

**One base class per merge, overriding steps.** A merge is a class extending a base class that holds the default rule
of every step as a method, and it overrides the methods it needs. A merge's rules sit in one class, but a rule needed
by several merges has to be moved into a helper they share, and the base class grows with every step.

**An abstract default per step, plugged in by configuration.** Each step has an abstract class and a default
implementation of it. A merge names, in its configuration, the implementation it uses for each step, and a kind that
needs other rules gets a subclass that replaces or extends the default. A customization is a class of its own that
several merges can use and that is tested on its own, but a merge with customizations has its rules spread over
several classes, and each abstract class becomes a contract its subclasses depend on.

#### How a merge is declared

**Configuration files.** Merges are declared in files apart from the code. A merge can be changed without touching a
class, but implementations are named by strings, their arguments need a schema and validation of their own, and
merges are declared differently from data sets.

**A class with a definition attribute, the way data sets are declared.** Each merge is a class carrying its
configuration as an attribute. Implementations are given as objects that static analysis checks, and merges and data
sets are declared alike, but changing a merge is a code change and a deploy.

## Decision

Conflation runs as **a pipeline of five steps**, publishes its result as **a data set of its own**, and each merge is
**a class with a definition attribute** that names **an implementation of an abstract default for each customizable
step**.

### The steps

1. **Fetch** reads the records the merge needs from the broker.
2. **Merge by location** groups the records that describe the same thing.
3. **Resolve conflicts** settles attributes the members of a group disagree on.
4. **Augment** adds facts from data sets that describe something else at the same place.
5. **Create** builds and publishes one entity per group.

Steps 2, 3 and 4 are customizable. Each is decided in an ADR of its own, which sets out its configuration, its
abstract class, its default and the limits a customization must keep. Fetch and create are the same for every merge,
so inputs, identity, provenance, publication and removal follow the same rules everywhere.

### Declaring a merge

A merge is a class carrying a definition attribute. It implements a marker interface, which holds no rules and only
marks the class as a merge, so merges are collected by a service tag the way data sets are. The attribute holds the
merge's identifier and the data model of its result, plus the keys each customizable step defines. The names in this and
the step ADRs' snippets are illustrative, and their exact shape is settled when they are implemented:

```php
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    // … the keys of merging by location, resolving conflicts and augmenting
)]
final class ToiletConflation implements ConflationInterface
{
}
```

A key a merge leaves out takes its step's default, where its step has one. A step's default and its customizations
extend the step's abstract class, so a merge that needs other rules for one step names a subclass in that key and keeps
the defaults for the rest. A customization written for one kind is a class any merge can name.

### Checks between steps

The pipeline checks what each step returns against the limits its ADR sets, such as that a record belongs to at most
one group. A run that breaks them fails without publishing, so a faulty customization cannot publish a wrong merge.

### 1. Fetch

- Every entity of the models the merge names is read from the broker, from every data set that publishes them, with
  the data set it came from and its full geometry.
- The merged data set's own entities are left out, as are data sets kept for testing when published data is merged,
  and the other way round.

### 5. Create

- Every merged entity is built in the merge's result model, an existing model of the kind, so merged entities use
  the same models as their inputs, as 003 requires.
- **Identity:** a merged entity's identifier derives from the identifier of its member that sorts first.
- **Provenance:** a merged entity lists the identifiers of every input entity it was built from.
- **Publication:** the merged data set has its own source identifier. The input data sets stay published unchanged.
- **Removal:** entities of the merged data set that a run no longer produces are deleted.

### Timing

- A merge runs after a full import, and only when every data set that publishes the models it reads imported
  successfully.
- What a merge does when a data set loses a record is decided with the lifecycle of records. Until a record that
  disappears upstream is removed from the broker, it keeps taking part in merges.

### Example

Two data sets list toilets. The merge groups record `A-17` from the first and record `B-903` from the second, 4 m
apart, as one toilet:

| Attribute      | `A-17`     | `B-903`       | Merged entity              |
|----------------|------------|---------------|----------------------------|
| Opening hours  | 08–20      | not stated    | 08–20                      |
| Wheelchair     | yes        | yes           | yes                        |
| Fee            | not stated | free          | free                       |
| Identifier     | `A-17`     | `B-903`       | derived from `A-17`        |
| Provenance     |            |               | `A-17`, `B-903`            |

Both inputs stay published as they were. A consumer who wants one picture reads the merged data set; one who trusts a
particular data set keeps reading that.

Rationale:

- A data set of its own leaves every input intact and attributable, and a wrong merge is fixed by rerunning, not by
  repairing inputs.
- Separate steps keep matching, resolving, augmenting and publishing decidable and testable on their own.
- An abstract default per step lets a simply described kind use configuration alone, gives the others code where
  configuration cannot express their rules, and lets that code be reused by other kinds.
- Fixed fetch and create steps keep identity, provenance, publication and removal the same for every merge.
- Declaring a merge as a class lets static analysis check the implementations it names, and declares merges the way
  data sets are declared.

## Consequences

### Positive

- One entity per real-world thing is available for every kind with a merge declared.
- Consumers who need a single picture read one data set; those who need a specific source keep reading it.
- A new kind needs a class with its configuration, and code only where the defaults do not suit it.
- A customization is tested on its own and can be reused by other merges.
- A customization that breaks a step's limits fails its run instead of publishing a wrong merge.

### Negative / Trade-offs

- Each thing is published once per input data set and once more merged, so queries that span data sets see it
  several times.
- A merge with customizations cannot be understood from its declaration alone, and its rules are spread over the
  classes it names.
- Each step's abstract class becomes a contract; changing it touches every subclass.
- Changing a merge is a code change and a deploy.
- A merged entity's identifier changes when its first-sorting member disappears, or when a new member sorts before it.
- Until records lost upstream are removed from the broker, merges keep publishing what they said.
- The licence of a merged data set follows from its inputs, and inputs without a stated licence leave it unsettled.
