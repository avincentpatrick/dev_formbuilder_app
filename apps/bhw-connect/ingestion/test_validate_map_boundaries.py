import unittest
from validate_map_boundaries import reconcile, valid_polygon

POLYGON = {'type': 'Polygon', 'coordinates': [[[120, 10], [121, 10], [121, 11], [120, 10]]]}

class BoundaryValidationTest(unittest.TestCase):
    def test_missing_ambiguous_duplicate_and_null_are_separate(self):
        features = [{'properties': {'id': code}, 'geometry': shape} for code, shape in [('a', POLYGON), ('a', POLYGON), ('split', POLYGON), ('null', None), (123, POLYGON)]]
        report = reconcile({'type': 'FeatureCollection', 'features': features}, ['01', '02', '03'], {'a': ['01'], 'split': ['02', '03'], 'null': ['02']}, 'id')
        self.assertEqual(report['duplicate_codes'], ['01'])
        self.assertEqual(report['ambiguous'], ['split'])
        self.assertEqual(report['missing'], ['03'])
        self.assertEqual(report['null_geometry'], ['null'])
        self.assertEqual(report['unmatched'], ['feature:4'])

    def test_invalid_rings_and_coordinates(self):
        self.assertTrue(valid_polygon(POLYGON))
        self.assertFalse(valid_polygon({'type': 'Point', 'coordinates': [120, 10]}))
        self.assertFalse(valid_polygon({'type': 'Polygon', 'coordinates': [[[120, 10], [121, 10], [181, 11], [120, 10]]]}))
        self.assertFalse(valid_polygon({'type': 'Polygon', 'coordinates': [[[120, 10], [121, 10], [121, 11], [122, 10]]]}))

if __name__ == '__main__':
    unittest.main()
