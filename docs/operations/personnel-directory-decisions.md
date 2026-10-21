# Personnel directory and administrative tables

Baseline: `07973feb028925c7633a79e71051b9554903d0a7`. The locked dependencies are Laravel 12.69.1, Filament 3.3.54, Livewire 3.8.7, PhpSpreadsheet 5.10.0, and Dompdf 3.1.2. Production Compose pins PostgreSQL 16.13; deployment must verify the running version separately. No framework upgrade is required.

## Authoritative data

`Employee` remains the personnel record; `User` remains the authentication principal. Table membership references the internal employee primary key. The employee ID remains the business identifier and is never editable through a table cell. Name/rank/station/phone/display-name changes call `EmployeeProfileService`, preserving its self-edit restrictions, linked-account synchronization and profile-event audit. Email, identity and employment changes continue through the protected employee resource actions. Deactivation preserves history.

`PersonnelSource` combines the employee profile with the latest published bid year at or before the current calendar year. It does not infer assignments from overtime, leave, daily staffing or an older term when a member is missing from the current term. Profile station and bid station are distinct labeled fields. Unknown values remain blank. Workbook differences and email match status are reconciliation evidence, not authority to change profiles.

## Native administration

The existing homepage `quickAccessItems` and `hub-tile` renderer supply the inspection card. Its destination remains `take-home-inspections.index`, available to existing signed-in members; only its footer anchor moves.

Personnel pages use native Filament tables, filters, editable columns, actions, pagination and the existing `EnterpriseTable` defaults. Filament 3 supports search, toggled columns and session persistence. Named views and ordered multi-column sorts use small allowlisted configuration records rather than another grid framework. Custom columns use metadata; adding a tracking column never changes the employee schema. Source column ordering is explicit, and source data is always read from the authoritative model.

Filament 3 references: [columns and sorting](https://filamentphp.com/docs/3.x/tables/columns/getting-started), [filters](https://filamentphp.com/docs/3.x/tables/filters/getting-started), [editable text](https://filamentphp.com/docs/3.x/tables/columns/text-input).

## Tracking and recovery

Table definitions, membership/entry records, personal saved views and audit events are relational records. Bounded JSON stores column configuration and per-member values. Foreign keys and unique table/member pairs prevent duplicate or orphan membership. Fixed membership is the default. Dynamic membership evaluates saved source criteria while retaining prior values for members who leave and return. Removing membership or columns retains recoverable tracking history; archiving/deleting a table never deletes an employee or account.

Metadata and per-member version checks detect conflicting saves. Transactions commit related tracking edits together. Large monolithic JSON documents are avoided: PostgreSQL updates lock an entire row, including its JSON contents. See [PostgreSQL 16 JSON design](https://www.postgresql.org/docs/16/datatype-json.html), [constraints](https://www.postgresql.org/docs/16/ddl-constraints.html), [locking](https://www.postgresql.org/docs/16/explicit-locking.html), and [Laravel 12 transactions](https://laravel.com/docs/12.x/database#database-transactions).

## Access and exports

Existing Hub authentication and administrator entitlement remain mandatory. Service authorization is repeated for table reads, mutations and exports; table ownership/visibility and employee membership are checked server-side. Public action arguments and component state are untrusted. Columns, filters, sort keys, option values, payload sizes and export formats are allowlisted. Text renders escaped. See [Laravel authorization](https://laravel.com/docs/12.x/authorization), [validation](https://laravel.com/docs/12.x/validation), [Livewire 3 security](https://livewire.laravel.com/docs/3.x/security), [OWASP IDOR](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html), [mass assignment](https://cheatsheetseries.owasp.org/cheatsheets/Mass_Assignment_Cheat_Sheet.html), and [XSS](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html).

Native Filament [CSV/XLSX exports](https://filamentphp.com/docs/3.x/actions/prebuilt-actions/export) were evaluated; they use queued batches and database notifications. For this roster of a few hundred members, the existing vehicle-roster pattern with the already-installed PhpSpreadsheet and Dompdf produces immediate scoped downloads without new infrastructure. Export service authorization re-reads requested members from the permitted table query. XLSX text is explicitly typed, identifiers retain leading zeroes, checkbox values are Boolean and numeric tracking stays numeric. CSV strings that can start formulas are neutralized; exports are private and not cached. See [OWASP CSV injection](https://owasp.org/www-community/attacks/CSV_Injection).

## Release boundary

The migrations are additive. A code rollback must leave new tracking tables and data intact; schema rollback is not an acceptable substitute for preserving entries created after release. Production release still requires exact source/artifact identity, isolated migration and recovery verification, authorized browser workflows and current runtime health. Local test success alone does not establish production acceptance.
