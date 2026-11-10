#!/usr/bin/env python3
"""Prepare additional FHSIS programmes; output and workbooks stay private."""
import argparse
import hashlib
import json
from collections import Counter
from decimal import Decimal
from pathlib import Path
import openpyxl
from prepare_fhsis import Builder, FILES, normalise, short_code, numeric


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


def child_health_specifications(directory):
    """Annual child-health source columns, reviewed against workbook headers."""
    specs = []
    def add(prefix, key, label, n, d, sex=False, start=7, denominator='', program='immunization', sex_denominators=None):
        matches = list(directory.glob(prefix+'*.xlsx'))
        if len(matches) != 1:
            raise ValueError(f'Expected one source: {prefix}')
        specs.append(dict(key=key, label=label, file=matches[0].name, n=n, d=d,
                          rate=n+1, sex=sex, sex_denominators=sex_denominators,
                          ages=None, sheet='Annual', start=start, coded=False,
                          program=program, denominator=denominator))
    for prefix, vaccine in [('11 Grade', 'td'), ('12 Grade', 'mr')]:
        for grade,n,d in [(1,5,2),(7,10,7)]:
            add(prefix, f'school_{vaccine}_grade{grade}',
                f'Grade {grade} learners given one dose of {vaccine.upper()} vaccine', n,d,True,
                denominator=f'Enrolled Grade {grade} learners')
    for dose,n in [(1,3),(2,5)]:
        add('13 9 year', f'school_hpv{dose}', f'HPV dose {dose}, 9-year-old females', n,2,
            denominator='Projected population of 9-year-old females')
    for age,n,d,sd in [('6_11',7,4,[2,3]),('12_59',14,11,[9,10])]:
        add('1 Sick', f'child_sick_vita_{age}m',
            f'High-risk sick children given vitamin A, {age.replace("_", "–")} months', n,d,True,8,
            denominator=f'Sick children aged {age.replace("_", "–")} months seen (sex-specific for breakdowns)',
            program='nutrition', sex_denominators=sd)
    for key,label,n,d,sd in [('ors','Diarrhea cases given ORS',7,4,[2,3]),
                           ('ors_zinc','Diarrhea cases given ORS with zinc',11,4,[2,3]),
                           ('diarrhea_treated','Diarrhea treatment total reported by source',13,4,None),
                           ('pneumonia_abx','Pneumonia cases given antibiotics',20,17,[15,16])]:
        add('2 3 Diarrhea', 'child_'+key, label+', 0–59 months',n,d,sd is not None,8,
            denominator=('Pneumonia' if d==17 else 'Diarrhea')+' cases aged 0–59 months seen (sex-specific where available)',
            program='child_health',sex_denominators=sd)
    return specs


def maternal_specifications(directory):
    """Annual maternal columns; source age rates use explicitly mapped denominators."""
    specs = []
    population = 'Source estimated population (all ages); age rates are shares of this same population, not age-specific coverage'
    def add(prefix, key, label, n, ages, d=2, denominator=population):
        matches = list(directory.glob(prefix+'*.xlsx'))
        if len(matches) != 1:
            raise ValueError(f'Expected one source: {prefix}')
        specs.append(dict(key='maternal_'+key, label=label, file=matches[0].name, n=n, d=d,
                          rate=n+1, sex=False, ages=ages, sheet='Annual', start=7,
                          coded=False, program='maternal', denominator=denominator))
    for key,n in [('normal',6),('low',11),('high',16)]:
        add('2. BMI', 'bmi_'+key, f'First-trimester pregnant women with {key} BMI', n,
            [[n-3,None,None],[n-2,None,None],[n-1,None,None]])
    def population_ages(n):
        return [[n-6,2,n-5],[n-4,2,n-3],[n-2,2,n-1]]
    add('3. Td', 'td_first', 'First pregnancy: at least two Td-containing vaccine doses',9,population_ages(9))
    add('3. Td', 'td_repeat', 'Second or later pregnancy: at least three Td-containing vaccine doses',17,
        [[11,None,None],[13,None,None],[15,None,None]])
    for key,label,n in [('ifa','Iron with folic acid',9),('mms','Multiple micronutrient',17),('calcium','Calcium carbonate for high-risk pregnancies',25)]:
        add('4. Prenatal', 'prenatal_'+key, label+' supplementation completed during pregnancy',n,population_ages(n))
    for prefix,key,test,outcome in [('5. Hepatitis','hepb','Hepatitis B screening','Hepatitis B reactive'),
                                    ('6. Anemia','anemia','CBC or hemoglobin and hematocrit testing','Anemia diagnosed'),
                                    ('7. GD','gdm','Gestational diabetes screening','Gestational diabetes positive')]:
        add(prefix,key+'_screened',test+' during pregnancy',9,population_ages(9))
        add(prefix,key+'_positive',outcome+' during pregnancy',18,[[12,3,13],[14,5,15],[16,7,17]],9,
            'Women screened/tested for this condition (matching age group for age breakdowns)')
    add('8. Deworm', 'dewormed', 'Pregnant women given one dose of deworming tablet',9,population_ages(9))
    for key,label,n in [('ifa','Postpartum women completing iron with folic acid supplementation',9),
                        ('vita','Postpartum women given vitamin A',17)]:
        add('2. PP Supp','postpartum_'+key,label,n,population_ages(n))
    return specs


def infectious_specifications(directory):
    """Annual infectious programmes; population rates retain their original scale."""
    specs=[]
    def add(prefix,key,label,n,d=None,rate=None,sex=True,ages=None,start=8,sheet='Annual',denominator=None,unit=None):
        matches=list(directory.glob(prefix+'*.xlsx'))
        if len(matches)!=1:raise ValueError(f'Expected one source: {prefix}')
        specs.append(dict(key='infect_'+key,label=label,file=matches[0].name,n=n,d=d,rate=rate,
                          sex=sex,ages=ages,start=start,sheet=sheet,coded=False,program='infectious',
                          denominator=denominator or 'No denominator: source count only',unit=unit))
    for prefix,key,name,result in [('1 2 Syphilis','syphilis','Syphilis','positive'),('3 4 HIV','hiv','HIV','screen-reactive')]:
        add(prefix,key+'_screened',f'Pregnant women screened for {name}',6,2,7,False,
            [[3,None,None],[4,None,None],[5,None,None]],7,
            denominator='Source projected population under 1 year (proxy denominator for pregnancy screening)')
        add(prefix,key+'_positive',f'Pregnant women {name} {result}',11,6,12,False,
            [[8,3,None],[9,4,None],[10,5,None]],7,
            denominator=f'Pregnant women screened for {name} (matching age group for age counts)')
    for age,n,d in [('1–4',5,2),('5–9',10,7)]:
        add('Annual Tbl 1_4','deworm_'+age.replace('–','_'),f'Children aged {age} years completing two deworming doses',n,d,n+1,
            denominator=f'Eligible population aged {age} years')
    add('Annual Tbl 10_19','deworm_10_19','Adolescents aged 10–19 years completing two deworming doses',5,2,6,
        denominator='Eligible population aged 10–19 years')
    add('Annual Tbl 10_19','deworm_wra','Women aged 20–49 years completing two deworming doses',8,7,9,False,
        denominator='Eligible female population aged 20–49 years')
    add('Filariasis','filaria_positive','Lymphatic filariasis positive cases',5,2,6,denominator='Total examined for lymphatic filariasis')
    add('Filariasis','filaria_disability','Lymphatic filariasis cases with disability/deformity',9,5,10,denominator='Lymphatic filariasis positive cases')
    add('Leprosy','leprosy_treatment','Leprosy cases undergoing treatment (prevalence)',5,2,6,denominator='Population',unit='cases per 10,000 population')
    add('Leprosy','leprosy_new','Newly detected leprosy cases',9,2,10,denominator='Population',unit='cases per 100,000 population')
    add('Rabies','animal_bites','Reported animal bites',4)
    add('Rabies','rabies_deaths','Deaths due to rabies (source rate per reported animal bites)',7,4,8,denominator='Reported animal bites; this is not a population mortality rate')
    for key,label,n,d in [('schisto_suspected','Suspected schistosomiasis cases',5,2),('schisto_acute','Acute clinically diagnosed schistosomiasis cases',9,5),('schisto_acute_confirmed','Acute schistosomiasis cases confirmed by stool examination',13,9)]:
        add('Schisto',key,label,n,d,n+1,denominator={2:'Total patients seen',5:'Suspected schistosomiasis cases',9:'Acute clinically diagnosed schistosomiasis cases'}[d])
    add('Schisto','schisto_chronic','Chronic clinically diagnosed schistosomiasis cases',4,sheet='Annual1',denominator='Count only; source rate withheld because its denominator cannot be established from the annual tables')
    add('Schisto','schisto_chronic_confirmed','Chronic schistosomiasis cases confirmed by stool examination',8,4,9,sheet='Annual1',denominator='Chronic clinically diagnosed schistosomiasis cases')
    add('Schisto','schisto_treated','Schistosomiasis cases treated in health facilities (source chronic table)',12,rate=13,sheet='Annual1',denominator='Confirmed acute plus confirmed chronic schistosomiasis cases; FHSIS 2025 Annual Report, p. 96. Rate undefined when denominator is zero.')
    specs[-1]['denominator_components']=[['Annual',13],['Annual1',8]]
    add('Schisto','schisto_referred','Confirmed chronic schistosomiasis cases referred to hospital',16,8,17,sheet='Annual1',denominator='Confirmed chronic schistosomiasis cases')
    return specs


def family_planning_specifications(directory):
    source='FP_2025_PH.xlsx'
    if not (directory/source).is_file():raise ValueError('Missing family-planning workbook')
    methods=[('btl','Female sterilisation (FSTR/BTL)'),('nsv','Male sterilisation (MSTR/NSV)'),
             ('condom','Condom'),('iud','IUD, interval'),('iud_pp','IUD, postpartum'),
             ('pop','Progestin-only pills (POP)'),('coc','Combined oral contraceptives (COC)'),
             ('injectable','Injectables'),('implant','Implants'),('implant_pp','Implants, postpartum'),
             ('ccm','Cervical mucus method (CCM)'),('bbt','Basal body temperature (BBT)'),
             ('stm','Symptothermal method (STM)'),('sdm','Standard days method (SDM)'),
             ('lam','Lactational amenorrhea method (LAM)'),('total','All methods, source total')]
    specs=[]
    for group,sheet,label in [('begin','CUB_A','Beginning users'),('new','NA_A','New acceptors'),
                             ('other','OA_A','Other acceptors'),('dropout','DO_A','Dropouts'),
                             ('end','CUE_A','Ending users')]:
        for i,(method,method_label) in enumerate(methods):
            n=5+4*i
            specs.append(dict(key=f'fp_{group}_{method}',label=f'{label}: {method_label}',file=source,
                              n=n,d=None,rate=None,sex=False,ages=[[n-3,None,None],[n-2,None,None],[n-1,None,None]],
                              start=7,sheet=sheet,coded=False,program='family_planning',
                              denominator='No denominator: source-reported count, including fractional values; not a unique-person annual total'))
    specs.append(dict(key='fp_demand_q4',label='Demand for modern family planning satisfied, ages 15–49',
                      file=source,n=18,d=17,rate=19,sex=False,ages=None,start=7,sheet='Demand Satisfied',
                      coded=False,program='family_planning',area_column=16,
                      denominator='Source Q4 women of reproductive age multiplied by total demand factor; retained from Demand Satisfied sheet'))
    return specs


def ncd_specifications(directory):
    specs=[]
    def add(prefix,key,label,n,d=None,rate=None,sex=True,sheet='Annual',denominator=None):
        matches=list(directory.glob(prefix+'*.xlsx'))
        if len(matches)!=1:raise ValueError(f'Expected one source: {prefix}')
        specs.append(dict(key='ncd_'+key,label=label,file=matches[0].name,n=n,d=d,rate=rate,
                          sex=sex,ages=None,start=8,sheet=sheet,coded=True,program='ncd',
                          denominator=denominator or 'No denominator: source count only'))
    for prefix,age in [('1a.','20_59'),('1b.','60plus')]:
        age_label='20–59 years' if age=='20_59' else '60 years and above'
        if age=='20_59':
            for key,label,n,d in [('assessed','Risk-assessed using PhilPEN',198,3),('htn','Identified with hypertension',202,198),('dm','Identified with type 2 diabetes',206,198)]:
                add(prefix,age+'_'+key,label+', '+age_label,n,d,n+1,sheet='RA',denominator='Projected population aged 20–59' if d==3 else 'Adults aged 20–59 risk-assessed using PhilPEN')
        for disease,label,first,den in [('htn','Antihypertensive',14,10),('dm','Antidiabetic',28,24)]:
            for offset,mode,desc in [(0,'facility','100% provided by facility'),(3,'oop','out of pocket'),(6,'total','total access')]:
                n=first+offset
                add(prefix,f'dec_{age}_{disease}_{mode}',f'{label} medication, {desc}, {age_label}',n,den if mode=='total' else None,n+1 if mode=='total' else None,
                    sheet='Dec',denominator=('Identified hypertensive patients' if disease=='htn' else 'Identified type 2 diabetes patients')+' in the same December age cohort' if mode=='total' else None)
    for prefix,age in [('1c.','20_59'),('1d.','60plus')]:
        for key,label,n in [('smoking','History of smoking',6),('binge','Binge drinking',10),('inactive','Insufficient physical activity',14),('diet','Unhealthy diet',18),('overweight','Overweight',22),('obese','Obesity',26)]:
            add(prefix,age+'_'+key,label+', '+('20–59 years' if age=='20_59' else '60 years and above'),n,3,n+1,denominator='Source risk-assessed population in the stated age cohort; risk factors may overlap')
    for key,label,n,d,den in [('vision_screened','Screened for visual acuity',6,3,'Senior citizens seen'),('eye_disease','Identified with eye disease',10,6,'Senior citizens screened for visual acuity'),('eye_referred','Referred to eye-care professionals',14,10,'Senior citizens identified with eye disease')]:
        add('2. Visual',key,label+', age 60 and above',n,d,n+1,denominator=den)
    add('3. Immunization','senior_ppv','Senior citizens receiving one PPV dose',6,3,7,denominator='Senior citizens seen who had not previously received PPV')
    add('3. Immunization','senior_flu','Senior citizens receiving one influenza vaccine dose',11,8,12,denominator='Senior citizens seen')
    for key,label,n,d,rate in [('via','VIA screening',4,None,None),('pap','Pap smear screening',5,None,None),('hpv','HPV DNA screening',6,None,None),('assessed','Assessed only',7,None,None),('screened','Screened or assessed, source total',8,3,9),('suspicious','Suspicious for cervical cancer',10,7,11),('suspicious_treated','Suspicious cases treated',12,10,13),('suspicious_referred','Suspicious cases referred',14,10,15),('suspicious_managed','Suspicious cases treated/referred, source total',16,10,17),('lesions','Positive for precancerous lesions',18,None,19),('lesions_treated','Precancerous lesions treated',20,18,21),('lesions_referred','Precancerous lesions referred',22,18,23),('lesions_managed','Precancerous lesions treated/referred, source total',24,18,25)]:
        den={3:'Projected women aged 30–65 minus women aged 30–65 screened in the previous two years (source target population)',7:'Women assessed only (source denominator, not all screened women)',10:'Women suspicious for cervical cancer',18:'Women positive for precancerous lesions'}.get(d)
        if key=='lesions':den='Women aged 30–65 screened by VIA, Pap smear or HPV DNA (sum of source method counts, excluding assessed-only women); FHSIS 2025 Annual Report, p. 88. Rate undefined when denominator is zero.'
        add('4a.', 'cervical_'+key,label+', women aged 30–65',n,d,rate,False,denominator=den)
        if key=='lesions':specs[-1]['denominator_components']=[['Annual',4],['Annual',5],['Annual',6]]
    for cohort,desc,items in [('highrisk','high-risk asymptomatic or symptomatic women aged 30–69',[(3,None,None,'cbe'),(4,None,None,'mammogram'),(5,None,None,'screened'),(6,3,7,'cbe_findings'),(8,4,9,'mammogram_findings'),(10,5,11,'findings'),(12,10,13,'care')]),('routine','asymptomatic women aged 50–69',[(16,15,17,'cbe'),(18,15,19,'mammogram'),(20,15,21,'screened'),(22,16,23,'cbe_findings'),(24,18,25,'mammogram_findings'),(26,20,27,'findings'),(28,26,29,'care')])]:
        labels={'cbe':'Clinical breast examination','mammogram':'Mammography','screened':'Breast screening, source total','cbe_findings':'Significant clinical breast examination findings','mammogram_findings':'Significant mammography findings','findings':'Significant breast findings, source total','care':'Breast findings linked to care'}
        for n,d,rate,key in items:
            den={3:'Clinical breast examinations in the same cohort',4:'Mammograms in the same cohort',5:'Screening total in the same cohort',10:'Significant findings in the same cohort',15:'Projected female population aged 50–69',16:'Clinical breast examinations in the same cohort',18:'Mammograms in the same cohort',20:'Screening total in the same cohort',26:'Significant findings in the same cohort'}.get(d)
            add('4b.',f'breast_{cohort}_{key}',labels[key]+', '+desc,n,d,rate,False,denominator=den)
    for age,n in [('0_9',5),('10_19',8),('20_59',11),('60plus',14)]:
        add('5. MH','mhgap_'+age,'Mental-health concerns screened using mhGAP, '+age.replace('_','–').replace('plus',' and above')+' years',n)
    add('5. MH','mhgap_total','Mental-health concerns screened using mhGAP, all ages',15,sex=False)
    return specs


def senior_ncd_specifications(directory):
    """Annual RA block corroborated by FHSIS 2025 report pp. 83–85, not quarter sums."""
    source='1b. NCD Meds Senior Citizens_nofml.xlsx'
    if not (directory/source).is_file():raise ValueError(f'Missing source: {source}')
    specs=[]
    for key,label,n,d in [('assessed','Risk-assessed using PhilPEN',198,3),('htn','Identified with hypertension',202,198),('dm','Identified with type 2 diabetes',206,198)]:
        specs.append(dict(key='ncd_60plus_'+key,label=label+', 60 years and above',file=source,
                          n=n,d=d,rate=n+1,sex=True,ages=None,start=8,sheet='RA',coded=True,program='ncd',
                          denominator='Projected population aged 60 and above' if d==3 else 'Adults aged 60 and above risk-assessed using PhilPEN'))
    return specs


def oral_health_specifications(directory):
    specs=[]
    def add(source,key,label,n,d=None,rate=None,denominator=None):
        if not (directory/source).is_file():raise ValueError(f'Missing source: {source}')
        specs.append(dict(key='oral_'+key,label=label,file=source,n=n,d=d,rate=rate,sex=True,
                          ages=None,start=8,sheet='Annual',coded=False,program='oral_health',
                          denominator=denominator or 'No denominator: source count only'))
    add('1. 1st Dental Visit.xlsx','infant_first','Infants aged 0–11 months having their first dental visit',6,3,7,
        'Projected population aged 0–11 months')
    for age,base,den in [('1_4',6,3),('5_9',24,21),('10_19',42,39),('20_59',60,57),('60plus',78,75)]:
        label=age.replace('_','–').replace('plus',' and above')+' years'
        for step,kind in [(0,'first'),(8,'two')]:
            for offset,setting in [(0,'facility'),(4,'nonfacility')]:
                n=base+step+offset
                add('2. Completed 2 Visits.xlsx',f'{age}_{kind}_{setting}',
                    ('First oral-health visit' if kind=='first' else 'Completed two oral-health visits')+', '+label+', '+('facility-based' if setting=='facility' else 'non-facility-based'),
                    n,den if age=='1_4' else None,n+1 if age=='1_4' else None,
                    'Projected population aged 1–4 years' if age=='1_4' else 'Count only; annual denominator sums quarterly populations, so the coverage rate is held for clarification')
    return specs


def maternal_oral_specifications(directory):
    """Pregnant cohort corroborated by report pp. 77, 80; retain counts, not coverage."""
    source='2. Completed 2 Visits.xlsx'
    if not (directory/source).is_file():raise ValueError(f'Missing source: {source}')
    specs=[]
    for key,label,n in [('first_facility','First oral-health visit, facility-based',97),
                        ('first_nonfacility','First oral-health visit, non-facility-based',102),
                        ('two_facility','Completed two oral-health visits, facility-based',107),
                        ('two_nonfacility','Completed two oral-health visits, non-facility-based',112)]:
        specs.append(dict(key='oral_pregnant_'+key,label=label+', pregnant women',file=source,
                          n=n,d=None,rate=None,sex=False,ages=[[n-3,None,None],[n-2,None,None],[n-1,None,None]],
                          start=8,sheet='Annual',coded=False,program='oral_health',
                          denominator='Count only; annual denominator sums quarterly population estimates, so coverage is withheld'))
    return specs


def vital_specifications(directory):
    source='1. Natality and Mortality_2025_PH.xlsx'
    if not (directory/source).is_file():raise ValueError('Missing vital-statistics workbook')
    specs=[]
    def add(key,label,n,d=None,rate=None,sheet='Annual_a',sex=False,ages=None,unit='count',denominator='No denominator: source count only'):
        specs.append(dict(key='vital_'+key,label=label,file=source,n=n,d=d,rate=rate,sheet=sheet,
                          sex=sex,ages=ages,start=7,coded=False,program='vital_statistics',unit=unit,denominator=denominator))
    add('live_births','Live births',4,sex=True)
    add('births_under10','Live births to mothers younger than 10 years',7)
    for age,n,d in [('10_14',8,5),('15_19',10,6)]:
        add('births_'+age,'Adolescent births, mothers aged '+age.replace('_','–'),n,d,n+1,
            unit='births per 1,000 age-group population',denominator='Source estimated population aged '+age.replace('_','–')+' years; sex is not stated in the population column header')
    for cause,base in [('direct',3),('indirect',15)]:
        for residence,offset in [('resident',0),('nonresident',5)]:
            n=base+offset+3
            add('maternal_'+cause+'_'+residence,cause.title()+' maternal deaths, '+residence,n,2,n+1,
                sheet='Annual_b',ages=[[n-3,None,None],[n-2,None,None],[n-1,None,None]],
                unit='deaths per 100,000 live births',denominator='Source total live births; age breakdowns retain counts only')
        n=base+10
        add('maternal_'+cause+'_total',cause.title()+' maternal deaths, all residences',n,2,n+1,
            sheet='Annual_b',unit='deaths per 100,000 live births',denominator='Source total live births')
    add('infant_deaths','Infant deaths',3,2,4,sheet='Annual_c',unit='deaths per 1,000 live births',denominator='Source total live births')
    return specs


def demographic_specifications(directory):
    source='Demographic_2025_EB_Final.xlsx'
    if not (directory/source).is_file():raise ValueError('Missing demographic workbook')
    specs=[]
    def add(key,label,n,d=None,rate=None,sheet='BGY & BHS',coded=False,unit='count',denominator='No denominator: source count only'):
        specs.append(dict(key='demo_'+key,label=label,file=source,n=n,d=d,rate=rate,sheet=sheet,
                          sex=False,ages=None,start=8,coded=coded,program='demographics',unit=unit,denominator=denominator))
    add('population','FHSIS estimated population',3,sheet='Health Workers',coded=True)
    add('households','FHSIS estimated households',36,sheet='Health Workers',coded=True)
    for key,label,n,unit in [('barangays','Barangays',3,'barangay'),('rhu','Rural health units',5,'rural health unit'),('bhs','Barangay health stations',7,'barangay health station')]:
        add(key,'FHSIS reported '+label.lower(),n)
        add('population_per_'+key,'FHSIS population per '+unit,2,n,n+1,unit='persons per '+unit,
            denominator='Source-reported '+label.lower()+'; ratio is undefined when the count is zero. Source ratio retained, not recomputed.')
        specs[-1]['numerator']='FHSIS estimated population in the BGY & BHS source table'
    return specs


def attach_composite_denominator(record, components):
    """Sum explicit source counts, preserving missing inputs and the published rate."""
    values=[numeric(c['value']) for c in components]
    record['denominator_exact']=(format(sum(Decimal(v) for v in values),'f')
                                 if all(v is not None for v in values) else None)
    record['source_denominator_components']=[{**{k:v for k,v in c.items() if k!='value'},'value':v}
                                              for c,v in zip(components,values)]


def combined_delivery_specifications(directory):
    files=list(directory.glob('1. Total Deliveries*.xlsx'))
    if len(files)!=1:raise ValueError('Expected one delivery source')
    return [dict(key='birth_combined_vaginal_caesarean',label='Combined vaginal–caesarean deliveries',
                 file=files[0].name,n=17,d=2,rate=18,sex=False,
                 ages=[[14,None,None],[15,None,None],[16,None,None]],sheet='Annual_b',start=7,
                 coded=False,program='maternal',denominator='Total deliveries; source Excel percentage fraction converted to percent by multiplying by 100')]


def convert_excel_percentage(record, number_format):
    # Only the reviewed source format is accepted; never infer scaling from magnitude.
    if number_format!='0.00%':raise ValueError('Unexpected delivery percentage format')
    raw=record['rate_pct']
    record['source_rate_raw']=raw
    record['source_rate_number_format']=number_format
    record['rate_pct']=format(Decimal(raw)*100,'f') if raw is not None else None
    record['over_100']=raw is not None and Decimal(record['rate_pct'])>100


class ExpansionBuilder(Builder):
    def raw_rows(self, source, sheet, start):
        for number,row in super().raw_rows(source,sheet,start):
            if FILES[source]=='4a. CA_nofml.xlsx' and row[1] is None and str(row[0]).startswith(('¹ -','² -','³ -','Note:')):
                continue
            yield number,row

    def named_rows(self, source, sheet, start, area_column=1):
        seen, region = set(), None
        workbook=openpyxl.load_workbook(self.directory/FILES[source], read_only=True, data_only=True)
        try:
            for number,row in enumerate(workbook[sheet].iter_rows(min_row=start,values_only=True),start):
                if not row or len(row)<area_column or not row[area_column-1]:
                    if row and str(row[0]).startswith(('Source:', 'Legend:', 'Asterisk', 'Zero (')):continue
                    if row and any(v is not None for v in row[area_column:]):
                        raise ValueError(f'Unnamed data: {source}/{sheet}/{number}')
                    continue
                name=str(row[area_column-1]).strip()
                if name in ('0 = No Case', '* = No Report'):continue
                if name.startswith(('Source:', 'Legend:', 'Asterisk', 'Zero (')):continue
                matched_name='Nueva Ecija' if name=='Nueva Eciea' and FILES[source]=='Annual Tbl 10_19___20_49_yrs_old_Pregnant_Women_EB_nofml.xlsx' else name
                if FILES[source]=='Schisto_2025_EB_nofml.xlsx':matched_name={'City of Quezon':'Quezon City','City of Pasay':'Pasay City'}.get(name,matched_name)
                if matched_name!=name:self.corrections.append(dict(source=FILES[source],sheet=sheet,row=number,original=name,matched=matched_name))
                candidates=self.canonical[normalise(matched_name)] or ({'19999'} if name=='SGA' else set())
                high=[c for c in candidates if self.geos[c]['geo_level'] in ('national','region','province')]
                candidates=high or [c for c in candidates if self.geos[c]['region_code']==region]
                if len(candidates)!=1:raise ValueError(f'Unresolved name: {source}/{sheet}/{number}: {name}, {candidates}')
                code=next(iter(candidates));geo=self.geos[code]
                if code in seen:raise ValueError(f'Duplicate area: {source}/{sheet}/{code}')
                if geo['geo_level']=='region':region=code
                elif geo['geo_level']!='national' and geo['region_code']!=region:
                    raise ValueError(f'Area outside current region: {name}')
                seen.add(code)
                raw_psgc=None if area_column==1 or row[0] is None else str(row[0]).removesuffix('.0')
                if raw_psgc and short_code(raw_psgc)!=code:
                    self.corrections.append(dict(source=FILES[source],sheet=sheet,row=number,raw_psgc=raw_psgc,name=name,to=code))
                yield number,row,dict(geo_code=code,geo_level=geo['geo_level'],source_psgc=raw_psgc,source_area_name=name,source_file=FILES[source],source_sheet=sheet,source_row=number)
        finally:workbook.close()
        self.sheet_counts[f'{source}/{sheet}']=len(seen)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory',type=Path)
    parser.add_argument('--reference',type=Path,required=True,help='Original private FHSIS directory containing TB geography and dim_geo.json')
    parser.add_argument('--retrieved',required=True)
    parser.add_argument('--programme-set', choices=['core','child-health','maternal','infectious','family-planning','ncd','oral-health','vital-statistics','demographics','combined-delivery','ncd-senior','oral-maternal'], default='core')
    args=parser.parse_args()
    geos=json.loads((args.reference/'dim_geo.json').read_text())
    b=ExpansionBuilder(args.reference,geos);b.directory=args.directory
    if args.programme_set in ('ncd','ncd-senior'):
        for code,geo in geos.items():
            if geo['geo_level']!='barangay':b.canonical[normalise(geo['geo_name'])].add(code)
        b.canonical[normalise('City of Carmona')]={'0402104'}
        b.canonical[normalise('Dr. Jose P. Rizal')]={'1705323'}
    specs={'core':specifications,'child-health':child_health_specifications,'maternal':maternal_specifications,'infectious':infectious_specifications,'family-planning':family_planning_specifications,'ncd':ncd_specifications,'oral-health':oral_health_specifications,'vital-statistics':vital_specifications,'demographics':demographic_specifications,'combined-delivery':combined_delivery_specifications,'ncd-senior':senior_ncd_specifications,'oral-maternal':maternal_oral_specifications}[args.programme_set](args.directory)
    definitions=[]
    cross_sheet_rows={}
    groups={}
    for spec in specs:groups.setdefault((spec['file'],spec['sheet'],spec['start'],spec['coded']),[]).append(spec)
    for (file,sheet,start,coded),group in groups.items():
        FILES[file]=file
        source_rows=b.named_rows(file,sheet,start,area_column=2) if args.programme_set in ('oral-health','oral-maternal') else (b.rows if coded else b.named_rows)(file,sheet,start)
        rate_formats={}
        if args.programme_set=='combined-delivery':
            workbook=openpyxl.load_workbook(args.directory/file,read_only=True,data_only=False)
            try:
                rate_formats={number:r[17].number_format for number,r in enumerate(workbook[sheet].iter_rows(min_row=start,max_col=18),start)}
            finally:workbook.close()
        for _,row,base in source_rows:
            for s in group:
                if s.get('area_column') and normalise(str(row[s['area_column']-1]))!=normalise(base['source_area_name']):
                    raise ValueError('Quarter geography columns disagree')
                before=len(b.indicators)
                b.indicator(base,row,s['key'],s['n'],s['d'],s['rate'])
                if s.get('denominator_components') and len(b.indicators)>before:
                    components=[]
                    for component_sheet,column in s['denominator_components']:
                        component_base,component_row=base,row
                        if component_sheet!=sheet:
                            cache_key=(file,component_sheet)
                            if cache_key not in cross_sheet_rows:
                                cross_sheet_rows[cache_key]={g['geo_code']:(g,r) for _,r,g in b.named_rows(file,component_sheet,start)}
                            if base['geo_code'] not in cross_sheet_rows[cache_key]:raise ValueError('Missing denominator geography')
                            component_base,component_row=cross_sheet_rows[cache_key][base['geo_code']]
                        components.append(dict(source_file=file,source_sheet=component_sheet,
                                               source_row=component_base['source_row'],source_column=column,
                                               geo_code=component_base['geo_code'],value=component_row[column-1]))
                    attach_composite_denominator(b.indicators[-1],components)
                if args.programme_set=='combined-delivery' and len(b.indicators)>before:
                    convert_excel_percentage(b.indicators[-1],rate_formats[base['source_row']])
                if s['sex']:
                    for i,(sex,n) in enumerate([('male',s['n']-2),('female',s['n']-1)]):
                        den=(s.get('sex_denominators') or [None,None])[i]
                        b.indicator(base,row,s['key'],n,den,breakdown=sex)
                for age,cols in zip(['10-14','15-19','20-49'],s['ages'] or []):b.indicator(base,row,s['key'],*cols,breakdown=age)
    for s in specs:
        definitions.append(dict(indicator_key=s['key'],program_area=s['program'],label=s['label'],numerator_def=s.get('numerator',s['label']),denominator_def=s['denominator'],unit=s.get('unit') or ('percent (0-100, may exceed)' if s['rate'] else 'count'),source_workbook=s['file'],uuc_criterion_d=s['key']=='birth_shp'))
    units={d['indicator_key']:d['unit'] for d in definitions}
    for r in b.indicators:
        if not units[r['indicator_key']].startswith('percent'):r['over_100']=False
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
    if args.programme_set!='core':
        bundle['programme_set']=args.programme_set
        qa['omitted_columns']=(['Repeat-pregnancy Td age-specific rates: source reports zeros despite nonzero counts; retained age counts only'] if args.programme_set=='maternal' else [])
    if args.programme_set=='infectious':
        qa['omitted_columns']=['Pregnancy deworming in STH workbook: duplicates maternal deworming with a different total; held for source reconciliation', 'Schistosomiasis Annual1 chronic diagnosis rate: denominator not established; retained counts only']
    if args.programme_set=='ncd':
        qa['omitted_columns']=['Senior annual risk-assessment/disease summary is prepared separately as ncd-senior after corroboration against the 2025 annual report', 'Medicine access: December snapshot only; monthly figures not summed to annual unique people']
    if args.programme_set=='ncd-senior':
        qa['source_year_resolution']={'report':'FHSIS 2025 Annual Report, printed pp. 83–85 (PDF pp. 94–96)', 'source_sheet':'RA', 'annual_columns':'GN:GY', 'conflicting_header':'Q4!A3: Annual 2024', 'decision':'Use 2025 annual RA values corroborated by report; retain conflicting header in provenance', 'quarter_caveat':'Region V and national annual counts differ from sums of quarters; quarterly years not established; do not replace annual values with quarter sums'}
        qa['omitted_columns']=['Quarterly/monthly repeats and December medicine access; sex-specific rates are not provided']
    if args.programme_set=='oral-health':
        qa['omitted_columns']=['Annual rates and denominators for ages 5–9, 10–19, 20–59 and 60+: denominators sum quarterly populations; retained visit counts', 'Pregnant-women final block is prepared separately as oral-maternal after annual-report cohort confirmation; coverage rates remain withheld']
    if args.programme_set=='oral-maternal':
        qa['cohort_resolution']={'report':'FHSIS 2025 Annual Report, printed pp. 77 and 80 (PDF pp. 88 and 91)', 'cohort':'Pregnant women aged 10–14, 15–19 and 20–49', 'conflicting_header':'Annual!CO2 labels senior citizens; retain original heading in source provenance', 'proxy_denominator':'CO5 under-one population is the report-defined proxy for pregnant women, not the service cohort', 'report_caption':'Figure 1.D.2.3 says non-facility in caption; legend and narrative explicitly distinguish both settings', 'completeness':'Some quarterly age cells have missing markers although annual counts are numeric; annual counts do not prove complete reporting in every quarter'}
        qa['omitted_columns']=['All coverage rates and denominators: annual denominator sums quarterly population snapshots; do not divide by four or infer corrected coverage', 'Quarterly repeats: retain source annual counts only']
    out=args.directory/'fhsis-expansion.json';out.write_text(json.dumps(bundle,ensure_ascii=False,separators=(',',':')))
    sha=hashlib.sha256(out.read_bytes()).hexdigest();(args.directory/'fhsis-expansion.sha256').write_text(sha+'  fhsis-expansion.json\n')
    (args.directory/'qa-report.json').write_text(json.dumps(qa,ensure_ascii=False,indent=2))
    print(json.dumps(dict(indicators=len(b.indicators),definitions=len(definitions),geographies=len(used),sha256=sha)))


if __name__=='__main__':main()
