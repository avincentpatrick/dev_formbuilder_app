import unittest, json, pathlib, importlib.util
p=pathlib.Path(__file__).parent/'engine.py';s=importlib.util.spec_from_file_location('engine',p);e=importlib.util.module_from_spec(s);s.loader.exec_module(e)
class EngineReferenceTests(unittest.TestCase):
 def spec(self,method,**extra):
  return dict(dict(method=method,title='Synthetic reference analysis',unit='count',design={'population':'sample','unit':'individual','independent':True},designStatement='Synthetic independent sample observations.'),**extra)
 def run_method(self,method,rows,**extra):return e.calculate({'rows':rows,'spec':self.spec(method,**extra)})
 def metric(self,r,name):return next(v for v in r['rows'] if v['metric']==name)['estimate']
 def test_phase_zero_description_and_complete_extraction(self):
  fixtures=json.loads((p.parents[2]/'docs/fixtures/ask-the-data-phase-0.json').read_text())
  r=self.run_method('describe',[{'x':v} for v in fixtures['descriptive']['values']],x='x')
  self.assertAlmostEqual(self.metric(r,'mean'),5);self.assertAlmostEqual(self.metric(r,'sample SD'),2.581988897471611);self.assertEqual(r['excluded'],1)
  r=self.run_method('describe',[{'x':1}]*100+[{'x':3}]*150,x='x');self.assertEqual(self.metric(r,'n'),250);self.assertAlmostEqual(self.metric(r,'mean'),2.2)
 def test_welch_independent_reference(self):
  r=self.run_method('welch',[{'a':a,'b':b}for a,b in zip([2,4,6,8],[4,6,8,10])],x='a',y='b',contrast='y minus x',independentGroups=True)
  self.assertEqual(self.metric(r,'mean difference'),2);self.assertAlmostEqual(r['diagnostics']['standardError'],1.8257418583505538);self.assertAlmostEqual(r['diagnostics']['df'],6);self.assertAlmostEqual(r['diagnostics']['t'],1.0954451150103321)
 def test_ols_normal_equation_reference(self):
  r=self.run_method('ols',[{'x':x,'y':y}for x,y in zip([1,2,3,4,5],[2,4,5,4,5])],y='y',predictors=['x'])
  self.assertAlmostEqual(r['rows'][0]['estimate'],2.2);self.assertAlmostEqual(r['rows'][1]['estimate'],.6);self.assertAlmostEqual(r['diagnostics']['rSquared'],.6)
 def test_known_logistic_group_odds(self):
  rows=[{'x':0,'y':y}for y in [0,0,0,1]]+[{'x':1,'y':y}for y in [0,1,1,1]]
  r=self.run_method('logistic',rows,y='y',predictors=['x']);self.assertAlmostEqual(r['rows'][1]['estimate'],9,places=7)
 def test_poisson_known_group_rates_and_offset(self):
  rows=[{'x':x,'y':y,'exposure':2}for x,y in zip([0,0,0,1,1,1],[1,2,1,3,2,4])]
  r=self.run_method('poisson',rows,y='y',predictors=['x'],exposure='exposure');self.assertAlmostEqual(r['rows'][1]['estimate'],2.25,places=7)
 def test_proportion_sums_counts_not_mean_percent(self):
  spec=self.spec('proportion',unit='percent',x='n',y='d',denominatorDefinition='disjoint population')
  spec['design'].update(disjoint=True,population='census',unit='area')
  r=e.calculate({'spec':spec,'rows':[{'n':10,'d':1000},{'n':30,'d':2000}]})
  self.assertAlmostEqual(self.metric(r,'proportion'),100*40/3000)
 def test_census_and_dependence_refused(self):
  for design in [{'population':'census','unit':'individual','independent':True},{'population':'sample','unit':'area','independent':True},{'population':'sample','unit':'individual','independent':False},{'population':'sample','unit':'individual','independent':True,'clustered':True}]:
   with self.assertRaises(e.Unsupported):e.calculate({'spec':dict(self.spec('pearson',x='a',y='b'),design=design),'rows':[{'a':v,'b':v}for v in range(8)]})
 def test_pairing_requires_unique_id(self):
  with self.assertRaises(e.Unsupported):self.run_method('paired',[{'a':v,'b':v+1,'id':1}for v in range(5)],x='a',y='b',pairId='id',contrast='y minus x')
 def test_sparse_contingency_refused(self):
  with self.assertRaises(e.Unsupported):self.run_method('chi_square',[{'a':a,'b':b}for a,b in [(0,0),(0,1),(1,0),(1,1)]],x='a',y='b')
 def test_separation_and_rank_deficiency_refused(self):
  with self.assertRaises(e.Unsupported):self.run_method('logistic',[{'x':v,'y':int(v>3)}for v in range(8)],y='y',predictors=['x'])
  with self.assertRaises(e.Unsupported):self.run_method('ols',[{'x':1,'y':v}for v in range(8)],y='y',predictors=['x'])
 def test_seeded_spearman_repeats(self):
  rows=[{'a':a,'b':b}for a,b in enumerate([2,3,1,7,6,8,5,4,9,10])]
  a=self.run_method('spearman',rows,x='a',y='b',seed=17);b=self.run_method('spearman',rows,x='a',y='b',seed=17);self.assertEqual(a,b);self.assertEqual(a['diagnostics']['seed'],17)
 def test_remaining_method_smoke(self):
  pairs=[{'a':a,'b':b,'id':i}for i,(a,b)in enumerate(zip([2,4,6,8,9],[4,5,7,12,13]))]
  for method in ['paired','mann_whitney','pearson']:
   r=self.run_method(method,pairs,x='a',y='b',pairId='id',contrast='y minus x',independentGroups=True);self.assertTrue(r['rows'])
  rows=[{'a':a,'b':b}for a,b,n in [(0,0,20),(0,1,10),(1,0,10),(1,1,20)]for _ in range(n)]
  for method in ['chi_square','fisher']:self.assertTrue(self.run_method(method,rows,x='a',y='b')['rows'])
 def test_exact_rank_and_fisher_references(self):
  r=self.run_method('spearman',[{'a':i,'b':5-i}for i in range(1,5)],x='a',y='b',seed=0)
  self.assertAlmostEqual(r['rows'][1]['estimate'],-1);self.assertAlmostEqual(r['rows'][1]['p'],1/12)
  rows=[{'a':a,'b':b}for a,b,n in [(0,0,1),(0,1,9),(1,0,11),(1,1,3)]for _ in range(n)]
  r=self.run_method('fisher',rows,x='a',y='b');self.assertAlmostEqual(r['rows'][0]['p'],.0027594561852200836)
 def test_time_and_rate_descriptions(self):
  r=self.run_method('time',[{'period':'2024','x':10},{'period':'2025','x':15}],x='x',group='period',periodDefinition='Consistent annual measurements')
  self.assertEqual(self.metric(r,'observed mean change'),5)
  r=self.run_method('rate',[{'n':10,'d':1000},{'n':30,'d':2000}],x='n',y='d',denominatorDefinition='person years',design={'population':'census','unit':'area','independent':False,'disjoint':True})
  self.assertAlmostEqual(self.metric(r,'rate'),1333.3333333333333)
 def test_non_numeric_not_coerced(self):
  with self.assertRaises(e.Unsupported):self.run_method('describe',[{'x':'001'}],x='x')
if __name__=='__main__':unittest.main()
