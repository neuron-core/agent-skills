---
name: sales-report
description: Produce a sales report from an orders CSV, accounting for cancellations and refunds.
---

# Sales report

Read references/reporting-rules.md before processing the orders.

## Analyze

Run:
`php scripts/analyze.php <orders.csv> --output <analysis.json>`

Inspect the analysis for excluded rows, cancellations, and refunds.
Explain any findings that affect the interpretation of the totals.

## Build the report

Run:
`php scripts/report.php <analysis.json> --output <report.md>`

Read the generated report and present its sales total, order count,
and relevant exclusions. Include the report location.
