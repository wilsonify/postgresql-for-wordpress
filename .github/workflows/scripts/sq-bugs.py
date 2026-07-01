#!/usr/bin/env python3
import json

data = json.load(open('/mnt/c/Users/toman/AppData/Local/Temp/opencode/sq_all.txt'))
for i in data['issues']:
    if i['rule'] in ('php:S930', 'php:S3699', 'php:S836', 'php:S2077'):
        line = i.get('line', '?')
        comp = i['component']
        msg = i['message']
        print(f'{i["rule"]} {comp}:{line}')
        print(f'  {msg}')
        if 'textRange' in i:
            tr = i['textRange']
            print(f'  lines {tr["startLine"]}-{tr["endLine"]}')
        print()
