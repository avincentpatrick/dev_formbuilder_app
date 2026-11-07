"""Synthetic checks only; never embed source records in this test."""
import unittest
from collections import Counter, defaultdict
from prepare_fhsis import Builder, numeric, integer, short_code


class PreparationTests(unittest.TestCase):
    def test_preserves_decimal_negative_zero_and_missing(self):
        self.assertEqual(numeric(0), '0')
        self.assertEqual(numeric('12.375'), '12.375')
        self.assertEqual(numeric('-3.5'), '-3.5')
        for missing in [None, '*', '#DIV/0!', '']:
            self.assertIsNone(numeric(missing))
        for invalid in ['NaN', 'unrecognised']:
            with self.assertRaises(ValueError):
                numeric(invalid)
        with self.assertRaises(ValueError):
            integer('1.5')

    def test_denominator_does_not_invent_report(self):
        builder = object.__new__(Builder)
        builder.indicators, builder.missing = [], Counter()
        builder.indicator({}, [None, 12, None], 'fic', 1, 2, 3)
        self.assertEqual(builder.indicators, [])
        builder.indicator({}, [0, 12, 0], 'fic', 1, 2, 3)
        self.assertEqual(builder.indicators[0]['numerator_exact'], '0')

    def test_named_province_prevents_duplicate_town_name_misassignment(self):
        builder = object.__new__(Builder)
        builder.corrections, builder.sheet_counts = [], {}
        builder.geos = {
            '01': {'geo_level': 'region', 'region_code': '01', 'province_code': None},
            '01001': {'geo_level': 'province', 'region_code': '01', 'province_code': '01001'},
            '0100101': {'geo_level': 'citymun', 'region_code': '01', 'province_code': '01001'},
            '0100201': {'geo_level': 'citymun', 'region_code': '01', 'province_code': '01002'},
        }
        builder.canonical = defaultdict(set, REGION={'01'}, PROVINCE={'01001'}, TOWN={'0100101', '0100201'})
        builder.raw_rows = lambda *args: iter([(7, ('0100000000', 'Region')), (8, ('0100100000', 'Province')), (9, ('9999999999', 'Town'))])
        mapped = list(builder.rows('fic', 'Annual', 7))
        self.assertEqual(mapped[-1][2]['geo_code'], '0100101')
        self.assertEqual(len(builder.corrections), 1)
        builder.raw_rows = lambda *args: iter([(7, ('9999999999', 'Town'))])
        with self.assertRaisesRegex(ValueError, 'Ambiguous geography'):
            list(builder.rows('fic', 'Annual', 7))

    def test_code_grains(self):
        self.assertEqual(short_code('0100000000'), '01')
        self.assertEqual(short_code('0100100000'), '01001')
        self.assertEqual(short_code('0100101000'), '0100101')


if __name__ == '__main__':
    unittest.main()
