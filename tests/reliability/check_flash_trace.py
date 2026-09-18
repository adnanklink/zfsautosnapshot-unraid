#!/usr/bin/env python3
"""Check strace -f -yy -e trace=%file output for boot-flash mutation attempts."""
import re
import sys
from pathlib import Path

lines = Path(sys.argv[1]).read_text().splitlines()
boot = [line for line in lines if '/boot' in line]
if not boot:
    raise SystemExit('FAIL: trace contains no boot-flash reads; coverage is unproven')
mutation = re.compile(r'\b(?:chmod|chown|lchown|fchmodat|fchownat|mkdir|mkdirat|rmdir|unlink|unlinkat|rename|renameat|renameat2|link|linkat|symlink|symlinkat|truncate|utime|utimes|utimensat|mknod|mknodat|setxattr|lsetxattr|removexattr|lremovexattr)\(')
failed = [line for line in boot if mutation.search(line) or ('open' in line and re.search(r'O_WRONLY|O_RDWR|O_CREAT|O_TRUNC|O_APPEND', line))]
if failed:
    raise SystemExit('FAIL: boot-flash mutation attempts:\n' + '\n'.join(failed))
print(f'PASS: {len(boot)} traced boot-path calls; zero file-write opens or path metadata mutation attempts')
