---
name: text-stats
description: Count words, lines and characters when the user requests text statistics.
---

# Text statistics

1. Read `config/analysis.yaml` for the `max_words` limit.
2. Run `python3 scripts/analyze.py --max-words <max_words>`, supplying the exact text through stdin.
3. Inspect the analysis JSON, then supply it through stdin to `python3 scripts/report.py`.
4. Return the report's PASS or FAIL result and its word count and limit.
