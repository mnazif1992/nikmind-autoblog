import sys, json, asyncio
sys.path.insert(0,'/tmp'); import render
from render import run
rels = sys.argv[1].split(',')
async def main():
    a = await run(['http://localhost:8811/' + r.replace('index.html','') for r in rels], 'ids')
    b = await run(['http://localhost:8812/' + r.replace('index.html','') for r in rels], 'ids')
    PRE = ['profileBody','patternsGrid','timelineItems','principlesGrid','boundaryGrid','beliefsGrid','doctorsGrid','servicesGrid','faqList','homeTherapists','reviewTrack','blogGrid','pricingGrid','reviewDots']
    for r in rels:
        x = a['http://localhost:8811/' + r.replace('index.html','')]; y = b['http://localhost:8812/' + r.replace('index.html','')]
        diff = [i for i in PRE if x['ids'].get(i) != y['ids'].get(i)]
        print(r, '| errors', y['errors'], '| h1', x['info']['h1'], '->', y['info']['h1'], '| vis', x['info']['vis'] == y['info']['vis'] or (x['info']['vis'], y['info']['vis']), '| text', x['info']['text'], y['info']['text'], '| container diffs', diff)
asyncio.run(main())
