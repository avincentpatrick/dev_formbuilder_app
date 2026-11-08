"""Synthetic source-shape tests; no published fact data."""
import tempfile
import unittest
from pathlib import Path
from collections import defaultdict, Counter
import openpyxl
from prepare_fhsis import FILES
from prepare_fhsis_expansion import ExpansionBuilder, maternal_specifications

class MappingTests(unittest.TestCase):
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
