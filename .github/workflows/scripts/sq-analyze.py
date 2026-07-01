#!/usr/bin/env python3
import json, sys

data = json.load(open('/mnt/c/Users/toman/AppData/Local/Temp/opencode/sq_all.txt'))
print(f'Total: {data["total"]} ({len(data["issues"])} in page)')

by_rule = {}
for i in data['issues']:
    r = i['rule']
    entry = by_rule.setdefault(r, {'count': 0, 'severity': i['severity'], 'type': i['type'], 'msg': i['message']})
    entry['count'] += 1

for k, v in sorted(by_rule.items(), key=lambda x: -x[1]['count']):
    print(f"  {v['count']:4d}x {k:33s} {v['severity']:7s} {v['type']:12s} {v['msg'][:80]}")
