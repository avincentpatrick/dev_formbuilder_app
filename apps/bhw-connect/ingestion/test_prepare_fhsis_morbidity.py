"""Synthetic structural checks, never published case counts."""
import unittest
from prepare_fhsis_morbidity import check_counts, resolve_area


class MorbidityTests(unittest.TestCase):
    def test_all_sex_and_age_totals_must_reconcile(self):
        cells=[2,3,5]*16+[32,48,80]
        self.assertEqual(len(check_counts(cells)),51)
        cells[2]=6
        with self.assertRaisesRegex(ValueError,'sex totals'):check_counts(cells)
        cells=[2,3,5]*16+[33,48,81]
        with self.assertRaisesRegex(ValueError,'age totals'):check_counts(cells)

    def test_negative_and_fractional_cases_fail(self):
        for value in [-1,0.5]:
            cells=[0]*51;cells[0]=value
            with self.assertRaisesRegex(ValueError,'Invalid morbidity'):check_counts(cells)

    def test_city_does_not_match_same_named_province_or_other_region(self):
        geos={
            'province':dict(geo_name='ILOILO',region_code='06',geo_level='province'),
            'huc':dict(geo_name='CITY OF ILOILO (HUC)',region_code='06',geo_level='province'),
            'city':dict(geo_name='CITY OF ILOILO (CAPITAL)',region_code='06',geo_level='citymun'),
            'other':dict(geo_name='ILOILO',region_code='07',geo_level='citymun'),
        }
        self.assertEqual(resolve_area('06','City of Iloilo',geos),'huc')
        self.assertEqual(resolve_area('06','Iloilo',geos),'province')
        with self.assertRaisesRegex(ValueError,'Unresolved geography'):
            resolve_area('07','City of Iloilo',geos)


if __name__=='__main__':unittest.main()
