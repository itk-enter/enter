# 006: Conflation - conflict resolution

| Field              | Value                                                  |
|--------------------|--------------------------------------------------------|
| **Created By**     | Martin Yde Granath, Jeppe Julius Krogh                 |
| **Date**           | 2026-10-13                                             |
| **Decision Maker** | ITK Dev team                                           |
| **Stakeholders**   | ITK Dev developers, data consumers, future maintainers |
| **Status**         | Draft                                                  |

## Context

Merge by location (005) joins the records that describe the same thing into a group, and each group becomes one merged
entity. But the records in a group do not always agree. Data sets are collected at different times, by different
people and for different purposes, and one of them can be wrong. The merged entity can give only one value for each
detail.

For example, three data sets describe the same public toilet:

| Detail        | Data set A | Data set B | Data set C |
|---------------|------------|------------|------------|
| Opening hours | 08–20      | 06–22      | 08–20      |
| Wheelchair    | yes        | no         | yes        |
| Fee           | free       | not stated | free       |

The fee is not a conflict, because one data set gives no value and the others agree. The opening hours and the
wheelchair access are conflicts, because the data sets give different values.

Which value is correct depends on the kind of thing and on the detail. For wheelchair access, a wrong "yes" sends a
person to a toilet that they cannot use, but a wrong "no" only sends them to a different toilet.

The simplest rule is to keep the value that most data sets give. But this rule cannot decide when only two data sets
disagree. Therefore, we need more than one rule to settle conflicts.

This ADR serves to decide how we choose one value when the records in a group give different values.

## Decision

Conflicts are settled by rules. Each rule looks at the values that the records give, and either picks one of them or
leaves the conflict undecided. The rules for each kind of thing, and their order, are chosen case by case, and the
first rule that decides settles the conflict.

A rule can decide in whatever way suits the kind of thing, and new rules can be added when they are needed. A rule
made for one kind of thing can be reused by other kinds, and it can apply to only some details.

A trust list and a majority rule are two examples. A trust list ranks data sets by how much we trust them, and keeps
the value from the highest-ranked data set that gives one. A majority rule keeps the value that more than half of the
data sets give, and otherwise leaves the conflict undecided.

There are no standard rules. If a kind of thing has no rules, or none of them decides, the detail is left out of the
merged entity. The conflicting values and their data sets are recorded on the merged entity, so they can be
corrected at the source. This lets us conflate a new kind of thing before anyone has chosen its rules, while the
records show which details still need a decision.

The location of a merged entity is decided in the same way as other details. When no rule decides it, we use the
location of the record whose identifier comes first, so every merged entity has a location.

## Consequences

### Positive

- A person chooses the rules that settle conflicts, and every run gives the same result.
- A new data set cannot win through a trust list until it is added to that list.
- A conflict that cannot be decided is visible on the merged entity, so it can be corrected at its source.
- A kind of thing can decide a detail by a rule that suits it, and other kinds can use the same rule.

### Negative

- Until a kind of thing has rules, every detail that its data sets disagree on is left out.
- A new data set can change a result, for example through a majority rule, or create a conflict that leaves a detail
  out.
- A merged entity can lack a detail that all its records give, when they give different values and no rule decides.
- Values that mean the same but are written differently are a conflict, unless a rule makes them the same.
- The location of a merged entity can change when the record that it was taken from is removed.
