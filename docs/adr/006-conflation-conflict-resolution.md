# 006: Conflation, conflict resolution — named data sets decide, through an abstract default

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath                                     |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

004 runs conflation as a pipeline whose third step, resolve conflicts, works on the groups formed by merging by
location: the records from different data sets that describe one real-world thing. Each group becomes one merged
entity, and each of its attributes can hold only one value.

The need for this step arises because two data sets can have different opinions on a shared attribute. They are
surveyed at different times, by different people, for different purposes, and one of them may simply be wrong. A group
of three records of one public toilet can state:

| Attribute     | Data set A | Data set B | Data set C |
|---------------|------------|------------|------------|
| Opening hours | 08–20      | 06–22      | 08–20      |
| Wheelchair    | yes        | no         | yes        |
| Fee           | free       | not stated | free       |

The fee is no conflict: one data set says nothing, and the others agree. Opening hours and wheelchair access are:
the data sets state different values, and the merged entity has to publish one or say that it does not know.

Which value is right differs per kind and per attribute. The data set kept by the toilet's operator is a fair guess
for opening hours, but for wheelchair access a wrong "yes" sends someone to a toilet they cannot use, while a wrong
"no" only makes them look elsewhere.

When an attribute is in conflict, how a value is chosen, what happens when none can be chosen, and how a kind uses
rules of its own are undecided.

This ADR serves to decide how conflicting attribute values within a group are settled, the default way of settling
them, and how a merge replaces or extends it.

### Drivers

- **Functional:** one value per attribute where it can be settled; a person decides which data sets are trusted; a
  conflict that cannot be settled is visible, not hidden; a kind can settle an attribute by a rule of its own.
- **Non-functional:** repeatable, so the same values give the same result; each way of settling testable on its own
  and reusable by other kinds.

### Options Considered

**Majority.** The value stated by more members than any other wins. It needs no configuration and lets a data set
join without a change to the merge, but it cannot settle a conflict between two members, a new data set votes before
anyone has judged it, and several data sets copying one mistaken source outvote a correct one.

**Naming the data sets in priority order.** The merge lists data sets, and the value of the earliest listed data set
that states one wins. It is predictable, a person decides whom to trust, and a new data set changes no result until it
is listed, but the list has to be kept up as data sets are added, one order holds for every attribute, and data sets
left off the list cannot settle a conflict.

**A priority order per attribute.** Each attribute gets its own list of data sets. It settles each attribute
precisely, but the configuration grows with every attribute and data set, and every new attribute needs a decision
before it is published.

**Most recent value.** The value observed most recently wins. It suits attributes that change, but needs a reliable
date per value, which few data sets give.

**Publishing every value.** The merged entity carries every stated value side by side, each marked with its data
set, as NGSI-LD allows. Nothing is lost and nothing has to be decided, but consumers get no single answer, which is
what the merge is for.

## Decision

Conflicts are settled by **naming the data sets in priority order**, through **source authority**, so they are
settled by who a person has chosen to trust, not by counting. Source authority is a subclass of **an abstract
conflict-resolution class**, and the merge lists it in its declaration, where a kind that needs rules of its own adds
further subclasses. A merge that lists no approach **settles no conflict**.

### Configuration

```php
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    defining: ['ModelA', 'ModelB'],
    conflictResolution: [new SourceAuthority(['data-set-a', 'data-set-c'])],
)]
final class ToiletConflation implements ConflationInterface
{
}
```

There is no default order of data sets, so a merge that leaves the key out settles no conflict: each attribute in
conflict is left out and recorded, as below. Agreeing values and values stated by one member are still used. This lets
a new merge run before anyone has chosen whom to trust, and shows which attributes need an order.

### When an attribute is in conflict

- An attribute is in conflict when the members of a group that state it state different values.
- An absent value never contradicts a stated one, so an attribute stated by one member, or stated alike by several,
  takes that value without any approach.

### Settling a conflict

- **Source authority:** the value of the earliest listed data set that states one settles it. Members whose data sets
  are not listed cannot settle it.
- **Order:** the listed approaches are tried in order, and the first that settles the conflict decides the value. The
  same list is used for every attribute; an approach that only concerns some attributes leaves the others to the
  next.
- **Unsettled:** a conflict no approach settles leaves the attribute out of the merged entity, and the conflicting
  values and the data sets stating them are recorded on it, so they can be followed up at their source.
- **Location:** a merged entity's location is settled like any other attribute. Where no approach settles it, the
  location of the member whose identifier sorts first is taken, so every merged entity has one.

#### Example

With the configuration above, the conflicts in the toilet group are settled like this:

| Attribute     | Data set A | Data set B | Data set C | Merged entity      |
|---------------|------------|------------|------------|--------------------|
| Opening hours | 08–20      | 06–22      | 08–20      | 08–20, from A      |
| Wheelchair    | yes        | no         | yes        | yes, from A        |
| Fee           | free       | not stated | free       | free, no conflict  |

In a group of records from data sets B and D only, neither of which is listed, a conflict between them stays
unsettled: the attribute is left out and both values are recorded on the merged entity.

### Customizing

A kind that needs other rules adds a subclass of the abstract conflict-resolution class to its list, before or after
source authority. A subclass receives one attribute and the values the members state for it, each with its data set,
and either returns a value or leaves the conflict to the next approach. It can concern every attribute or only some:

```php
conflictResolution: [new MostRestrictive(['wheelchair']), new SourceAuthority(['data-set-a', 'data-set-c'])],
```

Here `MostRestrictive` settles wheelchair access as "no" if any member says so, because a wrong "yes" sends someone to
a toilet they cannot use, and leaves every other attribute to source authority. In the example, the merged toilet is
then published as not wheelchair accessible.

### Limits every approach keeps

- An approach decides a value from the values the members state. It never sees or changes groups.
- An approach that cannot decide leaves the conflict to the next, rather than guessing.
- The same values give the same result, whatever order the members come in.

Rationale:

- Naming the data sets lets a person decide whom to trust, and keeps a new data set from changing results before
  anyone has judged it.
- Source authority settles a conflict between two members, which majority cannot.
- One list for every attribute keeps the configuration short; approaches that concern some attributes give the
  precision of a per-attribute order where it is needed.
- Leaving an unsettled attribute out and recording why keeps the merged data set from stating what no rule supports,
  and shows where the sources need correcting.
- Approaches as subclasses of one abstract class can be tested on their own and reused by other kinds.

## Consequences

### Positive

- Which data set wins a conflict is stated in the merge, and is the same for every run.
- A data set added for a model changes no conflict until it is listed.
- Each kind, and each attribute where needed, is settled by rules that suit it.
- Unsettled conflicts are visible on the merged entity, so they can be followed up at their source.
- An approach written for one kind can be listed by any other.

### Negative / Trade-offs

- The list of data sets has to be kept up as data sets are added and retired.
- A merge that leaves the key out drops every attribute its data sets disagree on, until someone lists them; the
  conflicts are recorded, but nothing stops the merge from publishing without them.
- A conflict between data sets that are not listed stays unsettled until someone lists them.
- One order holds for every attribute, unless a kind adds an approach of its own for some of them.
- A merged entity can lack an attribute every input states, when they state it differently and nothing settles it.
- A merged entity's location, where no approach settles it, changes when its first-sorting member disappears or a
  new member sorts before it.
- Approaches compare values as stated, so values that mean the same but are written differently count as a conflict
  unless an approach normalizes them.
