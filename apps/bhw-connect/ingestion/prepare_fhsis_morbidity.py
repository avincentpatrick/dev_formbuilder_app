#!/usr/bin/env python3
"""Prepare private cumulative morbidity chapters, retaining all source age/sex cells.

Monthly and Top 20 files are cross-checks, never extra facts. Each chapter is a
separate atomic append so neither Python, Node nor PostgreSQL needs a giant JSON.
"""
import argparse
from collections import Counter, defaultdict
from decimal import Decimal
import hashlib
import json
import re
from pathlib import Path
import openpyxl
from prepare_fhsis import Builder, normalise, numeric

REGIONS = dict(zip(['Region I','Region II','Region III','Region IV-A','Region V','Region VI',
                   'Region VII','Region VIII','Region IX','Region X','Region XI','Region XII',
                   'NCR','CAR','Caraga','Region IV-B','NIR','BARMM'],
                  ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','16','17','18','19']))
AGES = ['0-6d','7-28d','29d-11m','1-4','5-9','10-14','15-19','20-24','25-29',
        '30-34','35-39','40-44','45-49','50-54','55-59','60plus']
AGE_HEADERS = ['0-6 days','7-28 days','29 days to 11 months'] + [
    a+' years old' for a in AGES[3:-1]] + ['60 years and above']
CAVEATS = ['Region XII May is missing from the cumulative source.',
           'The source counts blank submitted cells as zero; a source zero is not proof of complete reporting.',
           'Cases are cumulative reports, not unique patients. Do not sum overlapping ICD categories.',
           'Regional/national rates retained without inventing a missing population denominator.',
           'Monthly and Top 20 summaries are not loaded again.']


def area_token(name):
    return normalise(re.sub(r'\s*\([^)]*\)', '', name)).removeprefix('CITYOF').removesuffix('CITY')


def resolve_area(region, name, geos):
    if name == 'Special Geographic Area (SGA)':
        candidates = ['19999']
    else:
        candidates = [c for c,g in geos.items() if g['geo_level'] in ('province','citymun')
                      and g['region_code'] == region and area_token(g['geo_name']) == area_token(name)
                      and ('CITY' in normalise(g['geo_name'])) == ('CITY' in normalise(name))]
        provinces = [c for c in candidates if geos[c]['geo_level'] == 'province']
        candidates = provinces or candidates
    if len(candidates) != 1 or geos[candidates[0]]['region_code'] != region:
        raise ValueError(f'Unresolved geography: {region}/{name}: {candidates}')
    return candidates[0]


def check_counts(values):
    """Validate 16 age triples and the grand triple without changing any count."""
    counts = [Decimal(numeric(v)) for v in values]
    if len(counts) != 51 or any(c < 0 or c != c.to_integral_value() for c in counts):
        raise ValueError('Invalid morbidity counts')
    if any(counts[i]+counts[i+1] != counts[i+2] for i in range(0,51,3)):
        raise ValueError('Morbidity sex totals disagree')
    if any(sum(counts[i:48:3]) != counts[48+i] for i in range(3)):
        raise ValueError('Morbidity age totals disagree')
    return counts


def prepare(directory, reference, retrieved):
    geos=json.loads((reference/'dim_geo.json').read_text())
    definitions=[dict(indicator_key=d['code'],**{k:v for k,v in d.items() if k!='code'})
                 for d in json.loads((Path(__file__).parent/'fhsis-morbidity-definitions.json').read_text())]
    report=[]
    resolved={}
    for chapter in range(1,20):
        defs=[d for d in definitions if d['indicator_key'].startswith(f'morb_{chapter:02}_')]
        source=defs[0]['source_workbook']
        sources=[source,source.replace('_Region_','_Province_')]
        by_label={d['label']:d for d in defs}
        b=object.__new__(Builder);b.indicators=[];b.missing=Counter()
        seen=set();totals={};regional=defaultdict(lambda:[Decimal(0)]*51)
        national=defaultdict(lambda:[Decimal(0)]*51)
        rate_checks=0;completeness=None
        for province,file in enumerate(sources):
            workbook=openpyxl.load_workbook(directory/file,read_only=True,data_only=True)
            rows=workbook['Data'].iter_rows(values_only=True)
            header=next(rows)
            expected=(['Region','Province/Area'] if province else ['Region'])+['Category','Disease/s','ICD-Code/s']
            expected += [age+'\n'+sex for age in AGE_HEADERS for sex in ['Male','Female','Total']]
            expected += ['Grand Total\nMale','Grand Total\nFemale','Grand Total\nBoth Sexes']
            expected += ['Population','Rate per 100,000 Population'] if province else ['Rate per 100,000 Population']
            if list(header)!=expected:raise ValueError(f'Unexpected columns: {file}')
            if not province:
                completeness=[list(r) for r in workbook['Completeness'].values if r and r[0]]
                missing=[(r[0],completeness[0][i]) for r in completeness[1:] for i,v in enumerate(r[1:],1) if v!='OK']
                if missing!=[('Region XII','May')]:raise ValueError('Reporting completeness changed; review source')
            for number,row in enumerate(rows,2):
                if not row or not row[0]:
                    if any(v is not None for v in row):raise ValueError('Unnamed morbidity data')
                    continue
                region=REGIONS.get(row[0]);name=row[1] if province else row[0]
                if province:
                    if (region,name) not in resolved:resolved[(region,name)]=resolve_area(region,name,geos)
                    code=resolved[(region,name)]
                else:code='PH' if name=='Philippines' else region
                if code is None:raise ValueError(f'Unknown region: {name}')
                label=f'{row[2+province]} ({row[3+province]})'
                definition=by_label.get(label)
                if not definition:raise ValueError(f'Unreviewed disease: {label}')
                key=definition['indicator_key'];identity=(code,key)
                if identity in seen:raise ValueError('Duplicate source area/disease')
                seen.add(identity)
                values=check_counts(row[4+province:55+province])
                totals[identity]=values
                if province:regional[(region,key)]=[a+c for a,c in zip(regional[(region,key)],values)]
                elif code!='PH':national[key]=[a+c for a,c in zip(national[key],values)]
                n=55+province;d=57 if province else None;rate=58 if province else 56
                if province and numeric(row[d-1]) is not None and Decimal(numeric(row[d-1]))>0 and numeric(row[rate-1]) is not None:
                    expected_rate=values[-1]/Decimal(numeric(row[d-1]))*100000
                    if abs(expected_rate-Decimal(numeric(row[rate-1])))>Decimal('0.000001'):
                        raise ValueError(f'Rate denominator mismatch: {file}:{number}')
                    rate_checks+=1
                base=dict(geo_code=code,geo_level=geos[code]['geo_level'],source_psgc=None,
                          source_area_name=name,source_file=file,source_sheet='Data',source_row=number)
                b.indicator(base,row,key,n,d,rate)
                for sex,col in [('male',n-2),('female',n-1)]:b.indicator(base,row,key,col,breakdown=sex)
                for i,age in enumerate(AGES):
                    for j,sex in enumerate(['male','female','total']):
                        b.indicator(base,row,key,5+province+i*3+j,breakdown=f'age:{age}:{sex}')
            workbook.close()
        for identity,values in regional.items():
            if totals.get(identity)!=values:raise ValueError(f'Province/region reconciliation failed: {identity}')
        for key,values in national.items():
            if totals.get(('PH',key))!=values:raise ValueError(f'Region/national reconciliation failed: {key}')
        for row in b.indicators:row['over_100']=False
        used=sorted({r['geo_code'] for r in b.indicators})
        if len(seen)!=len(used)*len(defs):raise ValueError('Incomplete disease/area grid')
        checkpoints=[r for r in b.indicators if r['geo_code']=='PH' and r['breakdown']=='total']
        qa=dict(indicator_rows=len(b.indicators),definitions=len(defs),geographies=len(used),
                source_rows=len(seen),rate_checks=rate_checks,reconciled_age_sex_cells=len(seen)*51,
                national_checkpoints=checkpoints,caveats=CAVEATS,completeness=completeness)
        bundle=dict(format='bhw-fhsis-expansion-v1',dataset_slug='fhsis-2025',programme_set=f'morbidity-{chapter:02}',
                    retrieved=retrieved,definitions=defs,indicators=b.indicators,qa=qa,
                    sources=[dict(file=f,sha256=hashlib.sha256((directory/f).read_bytes()).hexdigest()) for f in sources],
                    geographies=[{k:geos[c][k] for k in ['geo_code','geo_level','geo_name','parent_code']} for c in used])
        out=directory/f'morbidity-{chapter:02}.json'
        with out.open('w') as handle:json.dump(bundle,handle,ensure_ascii=False,separators=(',',':'))
        sha=hashlib.sha256(out.read_bytes()).hexdigest()
        out.with_suffix('.sha256').write_text(sha+'  '+out.name+'\n')
        report.append(dict(file=out.name,sha256=sha,**qa))
        print(json.dumps({k:v for k,v in report[-1].items() if k not in ['national_checkpoints','completeness','caveats']}),flush=True)
    (directory/'morbidity-qa.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory',type=Path)
    parser.add_argument('--reference',type=Path,required=True)
    parser.add_argument('--retrieved',required=True)
    args=parser.parse_args()
    prepare(args.directory,args.reference,args.retrieved)
