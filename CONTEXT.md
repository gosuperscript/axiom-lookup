# Delimited lookup

This library describes and evaluates typed lookups over delimited tabular files.

## Language

**Delimited table**:
A serializable description of one delimited file, including its location, parsing format, and partial column declarations. Every named declaration must exist in the file header; positional declarations are validated as headerless rows are read.
_Avoid_: File configuration, CSV source, dataset

**Column declaration**:
The serializable pairing of one named or positional column identity with its admitted Axiom type. Every column referenced by a lookup must have one declaration; a table rejects duplicate identities, optionality belongs to the declared type, and a required declaration rejects missing or absent values.
_Avoid_: Schema entry, column configuration

**Projection**:
The explicit value or exact-record shape returned from each selected row. A projection may only name column declarations; record fields map string output names to source column identities, and raw whole-row output and column-count-based shape inference are not part of the model. A scalar projection flattens row absence and an optional value's absence, while a record projection preserves them separately.
_Avoid_: Columns, selection

**Numeric fold**:
The typed reduction applied to matching rows by a numeric result. A numeric fold admits its input through its column declaration; optional inputs are skipped, while missing required inputs fail the lookup.
_Avoid_: Aggregate, aggregation

**Projected result**:
A lookup result that selects matching rows and applies a value or record projection to them. Row selection may keep the first, last, all, minimum, or maximum row or rows.
_Avoid_: Projected aggregate

**Numeric result**:
A lookup result that constructs a number by counting rows or folding one declared numeric column. It has no projection; row count is total and returns zero for an empty input, while sum and average are absent when no present values exist.
_Avoid_: Numeric projection
