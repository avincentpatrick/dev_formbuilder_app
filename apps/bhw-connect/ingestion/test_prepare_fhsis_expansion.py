"""Synthetic source-shape tests; no published fact data."""
import tempfile
import unittest
from pathlib import Path
from collections import defaultdict
import openpyxl
from prepare_fhsis import FILES
from prepare_fhsis_expansion import ExpansionBuilder

class MappingTests(unittest.TestCase):
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
