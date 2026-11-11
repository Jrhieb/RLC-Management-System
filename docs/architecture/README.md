# RLCP architecture documentation

Start with [inventory.md](inventory.md) for source evidence, roles, route map, schema declarations, flow rules and deployment key names. All implementation claims describe inspected source, not runtime behavior. No real data was used.

| Editable diagram | Named pages in order |
|---|---|
| [01-package-diagram.drawio](01-package-diagram.drawio) | 01A - Request and domain packages; 01B - Registration, review and messaging packages |
| [02-use-case-diagram.drawio](02-use-case-diagram.drawio) | 02A - Applicants and restricted staff; 02B - Director use cases; 02C - Approved Caseworker use cases |
| [03-domain-data-diagram.drawio](03-domain-data-diagram.drawio) | 03A - Program records and relationships; 03B - Accounts, registration and support tables |
| [04-registration-approval-flow.drawio](04-registration-approval-flow.drawio) | 04A - Email-first registration; 04B - Director review and decision delivery |
| [05-attendance-flow.drawio](05-attendance-flow.drawio) | 05A - NFC test-UID attendance; 05B - NFC ownership and staff lookup |
| [06-assistance-report-flow.drawio](06-assistance-report-flow.drawio) | 06A - Assistance release and idempotency; 06B - Reports and exports |

## PDF exports
The installed draw.io Desktop was detected, but each CLI export attempt timed out and produced no PDF. No PDF, page-order-in-PDF or rendered-legibility success is claimed. XML page order and node bounds were checked. Final routing corrections were made after failed export attempts; no successful rendered output exists for either revision.

Run the exact Windows export command from the project root in PowerShell:

```powershell
& .\docs\architecture\export-pdfs.ps1
```

The script uses the already detected executable and `--export --format pdf --all-pages` for all six files. Individual example:

```powershell
& 'C:\Program Files\draw.io\draw.io.exe' --export --format pdf --all-pages --output 'C:\rlcsystem\app\docs\architecture\01-package-diagram.pdf' 'C:\rlcsystem\app\docs\architecture\01-package-diagram.drawio' '--user-data-dir=C:\rlcsystem\app\docs\architecture\.drawio-profile'
```

After export, inspect every PDF page against the named-page list above for order, legibility and connector routing. The script checks nonempty files; that alone does not prove visual quality.

## Evidence and authoring helpers
- [verification.md](verification.md): completed checks and limitations.
- [xml-validation.json](xml-validation.json): structural results for the final XML.
- [export-status.json](export-status.json): per-file desktop export results.
- `build_diagrams.py`: native XML authoring helper; normally refuses to replace existing diagrams. Do not use its explicit replacement option after manual diagram edits.
- `export_diagrams.py`: bounded per-file export attempt helper used in this session.
- `export-pdfs.ps1`: Windows commands for every diagram.

The `.drawio-profile` directory was created solely for desktop export attempts. Its contents were not inspected. Automatic approval review rejected process/profile cleanup because sandbox approval is unavailable; it remains under this documentation directory. This runtime directory is not an architecture deliverable and should not be shared with the authored files.
