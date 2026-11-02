#!/usr/bin/env python3
"""Fail a publication check without echoing possible secret values."""
from pathlib import Path
import re, sys
root=Path(__file__).resolve().parents[1]
findings=[]
secret=re.compile(r"EA[A-Za-z0-9]{60,}|sk-[A-Za-z0-9_-]{18,}|(?:ghp|github_pat)_[A-Za-z0-9_]{20,}|c[k-s]_[a-f0-9]{25,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----")
for p in root.rglob('*'):
    if not p.is_file() or '.git' in p.parts: continue
    rel=p.relative_to(root)
    if p.name=='config.php' or p.name.startswith('.env') or p.suffix.lower() in {'.zip','.log','.csv','.xlsx','.sqlite','.db','.pem','.pfx'} or any(part in {'storage','uploads','backups','node_modules'} for part in rel.parts):
        findings.append((str(rel),'private/runtime file'));continue
    s=p.read_text(errors='replace')
    if secret.search(s):findings.append((str(rel),'possible credential'))
    if p.suffix in {'.php','.sql'}:
        if re.search(r'(?:8801[3-9][0-9]{8}|01[3-9][0-9]{8})',s):findings.append((str(rel),'non-example phone number'))
    if p.suffix=='.sql' and re.search(r'^\s*(?:INSERT|REPLACE)\s+INTO',s,re.M|re.I):findings.append((str(rel),'database data statement'))
for name,kind in findings:print(f'FAIL {name}: {kind}')
print('PASS publication guard' if not findings else 'Publication check failed')
sys.exit(bool(findings))
