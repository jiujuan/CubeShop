import base64, re, sys, html

p = sys.argv[1]
src = open(p, encoding='utf-8', errors='replace').read()
m = re.search(r'data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)', src)
if not m:
    print(''); sys.exit()
svg = base64.b64decode(m.group(1)).decode('utf-8', 'replace')
texts = re.findall(r'<text[^>]*>(.*?)</text>', svg, re.S)
print(html.unescape(''.join(t.strip() for t in texts)))
