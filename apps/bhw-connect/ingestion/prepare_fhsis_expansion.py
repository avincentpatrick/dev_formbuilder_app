#!/usr/bin/env python3
"""Prepare additional FHSIS programmes; output and workbooks stay private."""
import argparse
import hashlib
import json
from collections import Counter
from decimal import Decimal
from pathlib import Path
import openpyxl
from prepare_fhsis import Builder, FILES, normalise


def specifications(directory):
    """Reviewed 1-based source column maps. Each age cohort is a distinct indicator."""
    specs = []
    def file(prefix):
        matches = list(directory.glob(prefix+'*.xlsx'))
        if len(matches) != 1:
            raise ValueError(f'Expected one source: {prefix}')
        return matches[0].name
    def add(key, label, source, n, d=None, rate=None, sex=False, ages=None, sheet='Annual', start=7, coded=False, program='maternal', denominator=None):
        specs.append(dict(key=key, label=label, file=source, n=n, d=d, rate=rate, sex=sex, ages=ages,
                          sheet=sheet, start=start, coded=coded, program=program,
                          denominator=denominator or ('No denominator: source count only' if d is None else 'Source-reported eligible population or cases')))
    for prefix, vaccine, doses in [('4 DPT', 'pentavalent', 3), ('5 OPV', 'opv', 3), ('6 IPV', 'ipv', 2), ('7 PCV', 'pcv', 3), ('8 MMR', 'mmr', 2)]:
        source = file(prefix)
        for age, denominator, first in [('0_12m', 3, 6), ('13_23m', 4+4*doses, 7+4*doses)]:
            for dose in range(1,doses+1):
                n = first+4*(dose-1)
                add(f'vax_{vaccine}{dose}_{age}', f'{vaccine.upper()} dose {dose}, {age.replace("_", "–").replace("m", " months")}', source,
                    n, denominator, n+1, sex=True, coded=True, program='immunization', denominator=f'Projected population aged {age.replace("_", "–").replace("m", " months")}')
    source=file('1 2 3 CPAB')
    for key,label,n,d in [('bcg_0_28d','BCG within 0–28 days',6,3),('bcg_29d_1y','BCG at 29 days–1 year',10,3),('hepb_birth','Hepatitis B within 24 hours of birth',14,3),('hepb_late','Hepatitis B after 24 hours up to 14 days',18,3),('cpab','Children protected at birth',23,20)]:
        add('vax_'+key,label,source,n,d,n+1,sex=True,coded=True,program='immunization',denominator='Projected population aged 0–11 months' if d==20 else 'Projected population aged 0–12 months')
    add('pnc2','Women and newborns completing at least two postpartum check-ups',file('2. 2PNC'),13,5,14,ages=[[7,2,8],[9,3,10],[11,4,12]],start=9,denominator='Source-reported total deliveries (age-specific for age breakdowns)')
    add('pnc4','Women and newborns completing at least four postnatal visits',file('1. 4PNC'),33,17,34,ages=[[27,14,28],[29,15,30],[31,16,32]],denominator='Women who delivered and were tracked, including transfers in minus transfers out (age-specific for age breakdowns)')
    source=file('1. Total Deliveries')
    add('birth_deliveries','Total deliveries',source,5,ages=[[2,None,None],[3,None,None],[4,None,None]],sheet='Annual_a')
    for key,label,n in [('shp','Deliveries attended by skilled health professionals',12),('shp_physician','Deliveries attended by physicians',6),('shp_nurse','Deliveries attended by nurses',8),('shp_midwife','Deliveries attended by midwives',10),('facility','Facility-based deliveries',18),('facility_public','Deliveries in public facilities',14),('facility_private','Deliveries in private facilities',16)]:
        add('birth_'+key,label,source,n,5,n+1,sheet='Annual_a',denominator='Total deliveries')
    for key,label,n in [('vaginal','Vaginal deliveries',6),('caesarean','Caesarean deliveries',11)]:
        add('birth_'+key,label,source,n,2,n+1,sheet='Annual_b',denominator='Total deliveries',ages=[[n-3,None,None],[n-2,None,None],[n-1,None,None]])
    for key,label,n in [('full_term','Full-term deliveries',6),('preterm','Preterm deliveries',11),('fetal_death','Fetal deaths',16)]:
        add('birth_'+key,label,source,n,2,n+1,sheet='Annual_c',denominator='Total deliveries',ages=[[n-3,None,None],[n-2,None,None],[n-1,None,None]])
    source=file('2. Birth weight')
    for key,label,n in [('normal_weight','Live births weighing at least 2,500 g',3),('low_weight','Live births weighing below 2,500 g',5),('unknown_weight','Live births with unknown birth weight',7)]:
        add('birth_'+key,label,source,n,2,n+1,denominator='Total live births')
    source=file('1 Breastfeeding')
    add('nutrition_early_bf','Breastfeeding initiated within one hour of birth',source,5,2,6,sex=True,program='nutrition',denominator='Live births')
    add('nutrition_lbw_iron','Preterm or low-birth-weight infants given iron',source,10,7,11,sex=True,program='nutrition',denominator='Low-birth-weight infants in the source workbook')
    for prefix,key,label,second in [('2 3 Vitamin','vitamin_a','Vitamin A supplementation','12–59'),('4 MNP','mnp','Micronutrient powder supplementation','12–23'),('5 LNS','lns','Small-quantity lipid-based nutrient supplementation','12–23')]:
        for age,n,d in [('6–11',5,2),(second,10,7)]:
            add(f'nutrition_{key}_{age.replace("–", "_")}m',f'{label}, {age} months',file(prefix),n,d,n+1,sex=True,program='nutrition',denominator=f'Projected population aged {age} months')
    source=file('6-10 Nutritional')
    for group,treatment in [('mam','supplementary feeding'),('sam','outpatient therapeutic care')]:
        for key,label,n,d in [('identified','Identified',5,2),('enrolled','Enrolled/admitted',9,5),('cured','Cured',13,9),('noncured','Not cured',17,9),('defaulted','Defaulted',21,9),('died','Died',25,9)]:
            den={2:'Children aged 0–59 months seen at health facilities',5:f'Children identified with {group.upper()}',9:f'Children with {group.upper()} enrolled in {treatment}'}[d]
            add(f'nutrition_{group}_{key}',f'{group.upper()}: {label.lower()} ({treatment}), 0–59 months',source,n,d,n+1,sex=True,sheet=group.upper()+'_Annual',program='nutrition',denominator=den)
    return specs


class ExpansionBuilder(Builder):
    def named_rows(self, source, sheet, start):
        seen, region = set(), None
        workbook=openpyxl.load_workbook(self.directory/FILES[source], read_only=True, data_only=True)
        try:
            for number,row in enumerate(workbook[sheet].iter_rows(min_row=start,values_only=True),start):
                if not row or not row[0]:
                    if row and any(v is not None for v in row[1:]):
                        raise ValueError(f'Unnamed data: {source}/{sheet}/{number}')
                    continue
                name=str(row[0]).strip()
                if name.startswith(('Source:', 'Legend:', 'Asterisk', 'Zero (')):continue
                candidates=self.canonical[normalise(name)] or ({'19999'} if name=='SGA' else set())
                high=[c for c in candidates if self.geos[c]['geo_level'] in ('national','region','province')]
                candidates=high or [c for c in candidates if self.geos[c]['region_code']==region]
                if len(candidates)!=1:raise ValueError(f'Unresolved name: {source}/{sheet}/{number}: {name}, {candidates}')
                code=next(iter(candidates));geo=self.geos[code]
                if code in seen:raise ValueError(f'Duplicate area: {source}/{sheet}/{code}')
                if geo['geo_level']=='region':region=code
                elif geo['geo_level']!='national' and geo['region_code']!=region:
                    raise ValueError(f'Area outside current region: {name}')
                seen.add(code)
                yield number,row,dict(geo_code=code,geo_level=geo['geo_level'],source_psgc=None,source_area_name=name,source_file=FILES[source],source_sheet=sheet,source_row=number)
        finally:workbook.close()
        self.sheet_counts[f'{source}/{sheet}']=len(seen)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory',type=Path)
    parser.add_argument('--reference',type=Path,required=True,help='Original private FHSIS directory containing TB geography and dim_geo.json')
    parser.add_argument('--retrieved',required=True)
    args=parser.parse_args()
    geos=json.loads((args.reference/'dim_geo.json').read_text())
    b=ExpansionBuilder(args.reference,geos);b.directory=args.directory
    specs=specifications(args.directory)
    definitions=[]
    groups={}
    for spec in specs:groups.setdefault((spec['file'],spec['sheet'],spec['start'],spec['coded']),[]).append(spec)
    for (file,sheet,start,coded),group in groups.items():
        FILES[file]=file
        for _,row,base in (b.rows if coded else b.named_rows)(file,sheet,start):
            for s in group:
                b.indicator(base,row,s['key'],s['n'],s['d'],s['rate'])
                if s['sex']:
                    for sex,n in [('male',s['n']-2),('female',s['n']-1)]:b.indicator(base,row,s['key'],n,breakdown=sex)
                for age,cols in zip(['10-14','15-19','20-49'],s['ages'] or []):b.indicator(base,row,s['key'],*cols,breakdown=age)
    for s in specs:
        definitions.append(dict(indicator_key=s['key'],program_area=s['program'],label=s['label'],numerator_def=s['label'],denominator_def=s['denominator'],unit='percent (0-100, may exceed)' if s['rate'] else 'count',source_workbook=s['file'],uuc_criterion_d=s['key']=='birth_shp'))
    unique={(r['geo_code'],r['indicator_key'],r['breakdown']) for r in b.indicators}
    if len(unique)!=len(b.indicators):raise ValueError('Duplicate facts')
    checkpoints=[r for r in b.indicators if r['geo_code']=='PH' and r['breakdown']=='total']
    if len(checkpoints)!=len(definitions):raise ValueError('Missing national indicators')
    sources=[dict(file=f,sha256=hashlib.sha256((args.directory/f).read_bytes()).hexdigest()) for f in sorted({s['file'] for s in specs})]
    qa=dict(indicator_rows=len(b.indicators),definitions=len(definitions),by_indicator=dict(Counter(r['indicator_key'] for r in b.indicators)),sheet_geographies=b.sheet_counts,corrections=b.corrections,excluded=b.excluded,national_checkpoints=checkpoints,
            omitted_columns=['Combined vaginal-caesarean rate: source percentage column appears to contain an unscaled fraction', 'Abortion/miscarriage counts: fractional source values require clarification'])
    used=sorted({r['geo_code'] for r in b.indicators})
    bundle=dict(format='bhw-fhsis-expansion-v1',dataset_slug='fhsis-2025',retrieved=args.retrieved,sources=sources,definitions=definitions,indicators=b.indicators,
                geographies=[{k:geos[c][k] for k in ('geo_code','geo_level','geo_name','parent_code')} for c in used],qa=qa)
    out=args.directory/'fhsis-expansion.json';out.write_text(json.dumps(bundle,ensure_ascii=False,separators=(',',':')))
    sha=hashlib.sha256(out.read_bytes()).hexdigest();(args.directory/'fhsis-expansion.sha256').write_text(sha+'  fhsis-expansion.json\n')
    (args.directory/'qa-report.json').write_text(json.dumps(qa,ensure_ascii=False,indent=2))
    print(json.dumps(dict(indicators=len(b.indicators),definitions=len(definitions),geographies=len(used),sha256=sha)))


if __name__=='__main__':main()
