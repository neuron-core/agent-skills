import json
import sys

counts = json.loads(sys.stdin.buffer.read().decode("utf-8"))
status = "PASS" if counts["words"] <= counts["max_words"] else "FAIL"
print(f"{status}: {counts['words']} words, limit {counts['max_words']}.")
