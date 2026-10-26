import csv
import json
import re
import sys
from datetime import datetime
from pathlib import Path

if len(sys.argv) != 2 or not Path(sys.argv[1]).is_file():
    raise ValueError("Usage: validate.py <csv-file>")

rules = json.loads((Path(__file__).resolve().parent.parent / "config" / "validation.json").read_text(encoding="utf-8"))
errors = []
rows = 0
ids = set()

with open(sys.argv[1], encoding="utf-8", newline="") as source:
    reader = csv.reader(source)
    header = next(reader, [])

    for column in rules["required_columns"]:
        if column not in header:
            errors.append({"row": 1, "column": column, "reason": "Required column is missing."})

    for values in reader:
        rows += 1
        if len(header) != len(values):
            errors.append({"row": rows + 1, "column": None, "reason": "Column count does not match the header."})
            continue

        order = dict(zip(header, values))
        order_id = order.get("order_id", "")
        if not order_id or order_id in ids:
            errors.append({"row": rows + 1, "column": "order_id", "reason": "Order ID must be nonempty and unique."})
        ids.add(order_id)

        date = order.get("date", "")
        try:
            valid_date = datetime.strptime(date, "%Y-%m-%d").strftime("%Y-%m-%d") == date
        except ValueError:
            valid_date = False
        if not valid_date:
            errors.append({"row": rows + 1, "column": "date", "reason": "Expected a valid YYYY-MM-DD date."})

        if not order.get("customer", "").strip():
            errors.append({"row": rows + 1, "column": "customer", "reason": "Customer must be nonempty."})
        if not re.fullmatch(r"[0-9]+(?:\.[0-9]{1,2})?", order.get("total", "")):
            errors.append({"row": rows + 1, "column": "total", "reason": "Expected a nonnegative decimal amount."})
        if order.get("status", "") not in rules["statuses"]:
            errors.append({"row": rows + 1, "column": "status", "reason": "Unrecognized order status."})

print(json.dumps({"valid": not errors, "rows": rows, "errors": errors}, separators=(",", ":")))
