"""Synthetic source-shape tests; no published fact data."""
import tempfile
import unittest
from pathlib import Path
from collections import defaultdict, Counter
import openpyxl
from prepare_fhsis import FILES
from prepare_fhsis_expansion import ExpansionBuilder, maternal_oral_specifications, senior_ncd_specifications, maternal_specifications, demographic_specifications, convert_excel_percentage, attach_composite_denominator

class MappingTests(unittest.TestCase):
    def test_pregnant_oral_uses_age_counts_and_does_not_import_coverage(self):
        with tempfile.TemporaryDirectory() as folder:
            (Path(folder)/'2. Completed 2 Visits.xlsx').touch()
            specs=maternal_oral_specifications(Path(folder))
            row=[None]*113;row[92]=4000
            b=object.__new__(ExpansionBuilder);b.indicators=[];b.missing=Counter()
            for s in specs:
                self.assertFalse(s['sex'])
                n=s['n'];row[n-1]=12;row[n]=0.3
                row[n-4:n-1]=[2,4,6]
                b.indicator({},row,s['key'],n,s['d'],s['rate'])
                for age,cols in zip(['10-14','15-19','20-49'],s['ages']):
                    b.indicator({},row,s['key'],*cols,breakdown=age)
            self.assertEqual(len(b.indicators),16)
            self.assertEqual([r['numerator_exact'] for r in b.indicators],['12','2','4','6']*4)
            self.assertTrue(all(r['denominator_exact'] is None and r['rate_pct'] is None for r in b.indicators))

    def test_senior_annual_uses_annual_columns_and_distinct_denominators(self):
        with tempfile.TemporaryDirectory() as folder:
            (Path(folder)/'1b. NCD Meds Senior Citizens_nofml.xlsx').touch()
            specs=senior_ncd_specifications(Path(folder))
            row=[None]*207
            # Different quarter values must not be used as annual totals.
            for col,value in [(3,1000),(6,999),(198,200),(199,20),(202,50),(203,25),(206,10),(207,5)]:
                row[col-1]=value
            b=object.__new__(ExpansionBuilder);b.indicators=[];b.missing=Counter()
            for s in specs:
                self.assertEqual(s['sheet'],'RA')
                b.indicator({},row,s['key'],s['n'],s['d'],s['rate'])
            self.assertEqual([r['numerator_exact'] for r in b.indicators],['200','50','10'])
            self.assertEqual([r['denominator_exact'] for r in b.indicators],['1000','200','200'])
            self.assertEqual([r['rate_pct'] for r in b.indicators],['20','25','5'])

    def test_composite_denominator_preserves_missing_zero_and_source_rate(self):
        record={'rate_pct':'12.345','numerator_exact':'2'}
        attach_composite_denominator(record,[{'value':10,'source_column':4},{'value':6,'source_column':5}])
        self.assertEqual(record['denominator_exact'],'16')
        self.assertEqual(record['rate_pct'],'12.345')  # Never recompute the published rate.
        attach_composite_denominator(record,[{'value':0},{'value':0}])
        self.assertEqual(record['denominator_exact'],'0')
        for missing in [None,'*']:
            attach_composite_denominator(record,[{'value':10},{'value':missing}])
            self.assertIsNone(record['denominator_exact'])
            self.assertIsNone(record['source_denominator_components'][1]['value'])

    def test_excel_percentage_conversion_requires_reviewed_format_and_preserves_raw(self):
        record={'rate_pct':'0.125','over_100':False}
        convert_excel_percentage(record,'0.00%')
        self.assertEqual(record['rate_pct'],'12.500')
        self.assertEqual(record['source_rate_raw'],'0.125')
        self.assertFalse(record['over_100'])
        record={'rate_pct':'1.25','over_100':False}
        convert_excel_percentage(record,'0.00%')
        self.assertTrue(record['over_100'])
        with self.assertRaisesRegex(ValueError,'Unexpected delivery percentage format'):
            convert_excel_percentage({'rate_pct':'12.5'},'0.00')
        record={'rate_pct':None}
        convert_excel_percentage(record,'0.00%')
        self.assertIsNone(record['rate_pct'])

    def test_demographic_ratios_use_population_over_units_and_exclude_bhw(self):
        with tempfile.TemporaryDirectory() as folder:
            (Path(folder)/'Demographic_2025_EB_Final.xlsx').touch()
            specs={s['key']:s for s in demographic_specifications(Path(folder))}
            self.assertEqual(len(specs),8)
            self.assertEqual(specs['demo_households']['n'],36)
            self.assertFalse(any(s['n']==37 for s in specs.values()))
            s=specs['demo_population_per_rhu']
            b=object.__new__(ExpansionBuilder);b.indicators=[];b.missing=Counter()
            b.indicator({},['Area',1000,10,100,5,200,0,0],s['key'],s['n'],s['d'],s['rate'])
            self.assertEqual(b.indicators[0]['numerator_exact'],'1000')
            self.assertEqual(b.indicators[0]['denominator_exact'],'5')
            self.assertEqual(b.indicators[0]['rate_pct'],'200')
            s=specs['demo_population_per_bhs']
            b.indicator({},['Area',1000,10,100,5,200,0,0],s['key'],s['n'],s['d'],s['rate'])
            self.assertEqual(b.indicators[1]['denominator_exact'],'0')
            self.assertEqual(b.indicators[1]['rate_pct'],'0')

    def test_maternal_age_rates_keep_source_denominators_and_withhold_bad_td_rates(self):
        with tempfile.TemporaryDirectory() as folder:
            for prefix in ['2. BMI','3. Td','4. Prenatal','5. Hepatitis','6. Anemia','7. GD','8. Deworm','2. PP Supp']:
                (Path(folder)/(prefix+'.xlsx')).touch()
            specs={s['key']:s for s in maternal_specifications(Path(folder))}
            b=object.__new__(ExpansionBuilder);b.indicators=[];b.missing=Counter()
            # A screening age-rate is a share of all-age population; positivity uses screened age counts.
            row=[None]*19
            for column,value in [(2,1000),(3,100),(4,10),(12,5),(13,5)]:row[column-1]=value
            for key in ['maternal_hepb_screened','maternal_hepb_positive']:
                b.indicator({},row,key,*specs[key]['ages'][0],breakdown='10-14')
            self.assertEqual([r['denominator_exact'] for r in b.indicators],['1000','100'])
            self.assertEqual([r['rate_pct'] for r in b.indicators],['10','5'])
            row=[None]*18;row[10]=7;row[11]=0
            b.indicator({},row,'maternal_td_repeat',*specs['maternal_td_repeat']['ages'][0],breakdown='10-14')
            self.assertEqual(b.indicators[-1]['numerator_exact'],'7')
            self.assertIsNone(b.indicators[-1]['rate_pct'])
            self.assertIsNone(b.indicators[-1]['denominator_exact'])

    def test_infectious_source_alias_preserves_name_and_region_checks(self):
        with tempfile.TemporaryDirectory() as folder:
            name='Schisto_2025_EB_nofml.xlsx'
            w=openpyxl.Workbook();w.active.title='Annual1'
            for row in [['Region'],['City of Quezon',3],['0 = No Case'],['* = No Report']]:w.active.append(row)
            w.save(Path(folder)/name)
            b=object.__new__(ExpansionBuilder);b.directory=Path(folder);b.sheet_counts={};b.corrections=[]
            b.geos={'13':dict(geo_level='region',region_code='13'),'137404':dict(geo_level='citymun',region_code='13')}
            b.canonical=defaultdict(set,REGION={'13'},QUEZONCITY={'137404'})
            # Use the same normalisation as the geography reference.
            from prepare_fhsis import normalise
            b.canonical[normalise('Quezon City')]={'137404'}
            FILES[name]=name
            rows=list(b.named_rows(name,'Annual1',1))
            self.assertEqual(len(rows),2)
            self.assertEqual(rows[1][2]['source_area_name'],'City of Quezon')
            self.assertEqual(b.corrections[0]['matched'],'Quezon City')
            b.geos['137404']['region_code']='01'
            with self.assertRaisesRegex(ValueError,'Unresolved name|outside current region'):
                list(b.named_rows(name,'Annual1',1))

    def test_oral_hierarchy_overrides_wrong_code_for_same_named_municipality(self):
        with tempfile.TemporaryDirectory() as folder:
            w=openpyxl.Workbook();w.active.title='Annual'
            w.active.append(['0200000000','Region',1])
            w.active.append(['0102915000','Quirino',5])
            w.save(Path(folder)/'oral.xlsx')
            b=object.__new__(ExpansionBuilder);b.directory=Path(folder);b.sheet_counts={};b.corrections=[]
            b.geos={'02':dict(geo_level='region',region_code='02'),'02057':dict(geo_level='province',region_code='02'),'0102915':dict(geo_level='citymun',region_code='01')}
            b.canonical=defaultdict(set,REGION={'02'},QUIRINO={'02057','0102915'})
            FILES['oral']='oral.xlsx'
            rows=list(b.named_rows('oral','Annual',1,area_column=2))
            self.assertEqual(rows[1][2]['geo_code'],'02057')
            self.assertEqual(rows[1][2]['source_psgc'],'0102915000')
            self.assertEqual(b.corrections[0]['to'],'02057')

    def test_names_prefer_province_and_use_region_for_ambiguous_city(self):
        with tempfile.TemporaryDirectory() as folder:
            w=openpyxl.Workbook();s=w.active;s.title='Annual'
            for name in ['Region','Province','City']:
                s.append([name,12])
            w.save(Path(folder)/'synthetic.xlsx')
            b=object.__new__(ExpansionBuilder);b.directory=Path(folder);b.sheet_counts={}
            b.geos={'01':dict(geo_level='region',region_code='01'),'01001':dict(geo_level='province',region_code='01'),'0100101':dict(geo_level='citymun',region_code='01'),'0200101':dict(geo_level='citymun',region_code='02')}
            b.canonical=defaultdict(set,REGION={'01'},PROVINCE={'01001','0200101'},CITY={'0100101','0200101'})
            FILES['synthetic']='synthetic.xlsx'
            rows=list(b.named_rows('synthetic','Annual',1))
            self.assertEqual([r[2]['geo_code'] for r in rows],['01','01001','0100101'])
            self.assertTrue(all(r[2]['source_psgc'] is None for r in rows))
            b.canonical['CITY']={'0100101','01001'}
            with self.assertRaisesRegex(ValueError,'Duplicate area'):
                list(b.named_rows('synthetic','Annual',1))

if __name__=='__main__':unittest.main()
