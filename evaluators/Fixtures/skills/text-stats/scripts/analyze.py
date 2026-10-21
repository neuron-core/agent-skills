import argparse
import json
import sys

parser = argparse.ArgumentParser()
parser.add_argument("--max-words", type=int, required=True)
args = parser.parse_args()
text = sys.stdin.buffer.read().decode("utf-8")
print(json.dumps({
    "words": len(text.split()),
    "lines": len(text.splitlines()),
    "characters": len(text),
    "max_words": args.max_words,
}))
