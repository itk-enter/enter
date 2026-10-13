# 006: Conflation, conflict resolution — ordered approaches, each extending an abstract default

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

Which value is right differs per kind and per attribute. A majority is a fair guess for opening hours, but for
wheelchair access a wrong "yes" sends someone to a toilet they cannot use, while a wrong "no" only makes them look
elsewhere.

When an attribute is in conflict, how a value is chosen, what happens when none can be chosen, and how a kind uses
rules of its own are undecided.

This ADR serves to decide how conflicting attribute values within a group are settled, the default way of settling
them, and how a merge replaces or extends it.

### Drivers

- **Functional:** one value per attribute where it can be settled; a conflict that cannot be settled is visible, not
  hidden; a kind can settle an attribute by a rule of its own; a data set added for a kind takes part without a
  change to the merge.
- **Non-functional:** repeatable, so the same values give the same result; each way of settling testable on its own
  and reusable by other kinds.

### Options Considered

**Naming the data sets in priority order.** The first data set in the list that states a value wins. It is simple
and predictable, but every data set added for a kind needs the list changed, one order has to hold for every
attribute, and a data set that is best for one attribute and poor for another cannot be placed.

**A priority order per attribute.** Each attribute gets its own list of data sets. It settles each attribute
precisely, but the configuration grows with every attribute and data set, and every new attribute needs a decision
before it is published.

**Ordered approaches.** The merge lists ways of settling a conflict, such as majority and then the authority of named
data sets, tried in order until one settles it. A data set added for a kind takes part without a change, and each
kind is settled the way that suits it, but a new data set's values take part before anyone has judged them, and some
conflicts stay unsettled.

**Most recent value.** The value observed most recently wins. It suits attributes that change, but needs a reliable
date per value, which few data sets give.

**Publishing every value.** The merged entity carries every stated value side by side, each marked with its data
set, as NGSI-LD allows. Nothing is lost and nothing has to be decided, but consumers get no single answer, which is
what the merge is for.

## Decision

Conflicts are settled by **ordered approaches**. Each approach is a subclass of **an abstract conflict-resolution
class**, the merge lists the approaches it uses in its declaration, and a kind that needs rules of its own adds a
subclass to the list.

### Configuration

```php
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    conflictResolution: [new Majority(), new SourceAuthority(['data-set-a', 'data-set-c'])],
)]
final class ToiletConflation
{
}
```

A merge that leaves the key out uses majority alone.

### When an attribute is in conflict

- An attribute is in conflict when the members of a group that state it state different values.
- An absent value never contradicts a stated one, so an attribute stated by one member, or stated alike by several,
  takes that value without any approach.

### Settling a conflict

- The approaches are tried in the listed order, and the first that settles the conflict decides the value. The same
  list is used for every attribute; an approach that only concerns some attributes leaves the others to the next.
- **Majority:** the value stated by more members than any other settles it. A tie does not.
- **Source authority:** configured with an ordered list of data sets. The value of the earliest listed data set that
  states one settles it. Members whose data sets are not listed cannot settle it.
- **Unsettled:** a conflict no approach settles leaves the attribute out of the merged entity, and the conflicting
  values and the data sets stating them are recorded on it, so they can be followed up at their source.
- **Location:** a merged entity's location is settled like any other attribute. Where no approach settles it, the
  location of the member whose identifier sorts first is taken, so every merged entity has one.

#### Example

With the configuration above, the conflicts in the toilet group are settled like this:

| Attribute     | Majority             | Source authority  | Merged entity |
|---------------|----------------------|-------------------|---------------|
| Opening hours | 08–20 (A and C)      | not tried         | 08–20         |
| Wheelchair    | yes (A and C)        | not tried         | yes           |
| Fee           | no conflict          | not tried         | free          |

With only data sets A and B in the group, majority ties on both attributes, and source authority settles them from
data set A, the earliest listed that states them.

### Customizing

A kind that needs other rules adds a subclass of the abstract conflict-resolution class to its list. A subclass
receives one attribute and the values the members state for it, each with its data set, and either returns a value or
leaves the conflict to the next approach. It can concern every attribute or only some:

```php
conflictResolution: [new MostRestrictive(['wheelchair']), new Majority(), new SourceAuthority(['data-set-a'])],
```

Here `MostRestrictive` settles wheelchair access as "no" if any member says so, and leaves every other attribute to
majority and source authority. In the example, the merged toilet is then published as not wheelchair accessible.

### Limits every approach keeps

- An approach decides a value from the values the members state. It never sees or changes groups.
- An approach that cannot decide leaves the conflict to the next, rather than guessing.
- The same values give the same result, whatever order the members come in.

Rationale:

- Ordered approaches let a data set join a kind without changing the merge, and let each kind settle conflicts the way
  that suits it.
- One list for every attribute keeps the configuration short; approaches that concern some attributes give the
  precision of a per-attribute order where it is needed.
- Leaving an unsettled attribute out and recording why keeps the merged data set from stating what no rule supports,
  and shows where the sources need correcting.
- Approaches as subclasses of one abstract class can be tested on their own and reused by other kinds.

## Consequences

### Positive

- A data set added for a model takes part in settling conflicts without a change to the merge.
- Each kind, and each attribute where needed, is settled by rules that suit it.
- Unsettled conflicts are visible on the merged entity, so they can be followed up at their source.
- An approach written for one kind can be listed by any other.

### Negative / Trade-offs

- A data set added for a model affects merges before anyone has judged its values, including through majority.
- Majority cannot settle a conflict between two members, so merges of two data sets depend on source authority or an
  approach of their own.
- An attribute settled by majority can be wrong where several data sets copy the same mistaken source.
- A merged entity can lack an attribute every input states, when they state it differently and nothing settles it.
- A merged entity's location, where no approach settles it, changes when its first-sorting member disappears or a
  new member sorts before it.
- Approaches compare values as stated, so values that mean the same but are written differently count as a conflict
  unless an approach normalizes them.
