#!/usr/bin/env python3
import json

data = json.load(open('/mnt/c/Users/toman/AppData/Local/Temp/opencode/sq_all.txt'))
by_file = {}
for i in data['issues']:
    f = i['component']
    by_file.setdefault(f, []).append(i)
for f in sorted(by_file.keys()):
    print(f'{f}: {len(by_file[f])} issues')
    for i in sorted(by_file[f], key=lambda x: (x.get("line") or 0, x["rule"])):
        line = i.get('line', 0)
        line_str = f':{line}' if line else ''
        rule = i['rule']
        sev = i['severity']
        msg = i['message'][:80]
        print(f'  {f}{line_str:>6s} {rule:28s} {sev:7s} {msg}')
