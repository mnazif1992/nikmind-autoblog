import sys, json, asyncio, base64
from playwright.async_api import async_playwright
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')
async def handle(r):
    u = r.request.url
    if r.request.resource_type == 'image' or u.endswith(('.webp', '.jpg', '.png', '.jpeg', '.svg')): await r.fulfill(status=200, content_type='image/png', body=PNG)
    elif u.startswith('http://localhost'): await r.continue_()
    else: await r.abort()
async def run(urls, mode):
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
        res = {}
        for u in urls:
            pg = await b.new_page(viewport={'width': 1280, 'height': 900})
            errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:300]))
            await pg.route('**/*', handle)
            await pg.goto(u, wait_until='load'); await pg.wait_for_timeout(1000)
            ids = await pg.evaluate('''() => { const o = {}; document.querySelectorAll('[id]').forEach(e => { o[e.id] = e.innerHTML; }); return o; }''')
            info = await pg.evaluate('''() => ({h1: [...document.querySelectorAll('h1')].filter(e=>e.offsetParent).map(e=>e.innerText.trim()), vis: [...document.querySelectorAll('[data-page]')].filter(e=>getComputedStyle(e).display!='none').map(e=>e.id||e.dataset.page), text: document.body.innerText.length, cls: document.body.className})''')
            if mode == 'shot': await pg.screenshot(path='/tmp/shots/' + u.split('/', 3)[3].strip('/').replace('/', '_') + ('_' + sys.argv[3] if len(sys.argv) > 3 else '') + '.png', full_page=True)
            res[u] = {'ids': ids, 'errors': errs, 'info': info}
            await pg.close()
        await b.close()
        return res
if __name__ == '__main__':
    out = asyncio.run(run(sys.argv[1].split(','), sys.argv[2]))
    json.dump(out, open('/tmp/render-out.json', 'w'), ensure_ascii=False)
