"""Fixed, versioned methods only. JSON input/output files; no eval, shell or generated code."""
import sys, json, math, hashlib, warnings, platform
from types import SimpleNamespace
import numpy as np
import scipy
from scipy import stats
import pandas as pd
import statsmodels
import statsmodels.api as sm
from statsmodels.stats.multitest import multipletests
from statsmodels.stats.proportion import proportion_confint
VERSION = 'bhw-statistics/1'

def fmt(value):
    return format(value, '.3e') if value != 0 and (abs(value)<.001 or abs(value)>=1e9) else format(value, '.3f')

class Unsupported(ValueError): pass

def require(test, message):
    if not test: raise Unsupported(message)

def numeric(frame, columns):
    for col in columns:
        require(col in frame, f'Column not found: {col}')
        present = frame[col].dropna()
        require(all(isinstance(x, (int, float)) and not isinstance(x, bool) and math.isfinite(x) for x in present), f'{col}: numeric values required; strings are not silently converted')
    return frame[columns].dropna().astype(float)

def calculate(manifest):
    spec, rows = manifest['spec'], manifest['rows']
    require(0 < len(rows) <= 50000, 'Need 1–50,000 complete input rows; truncated previews are not valid inputs')
    frame = pd.DataFrame(rows)
    method = spec['method']; design = spec['design']; c = spec.get('confidence', .95)
    require(.8 <= c <= .99, 'Confidence must be between .80 and .99')
    alpha = 1-c; out = []; notes = ['Missing values use explicit complete-case exclusion; no imputation.', 'Associations do not establish causation.']
    metadata=manifest.get('provenance',{}).get('metadata',{})
    notes.extend('Source quality note: '+str(message) for message in metadata.get('qualityWarnings',[])[:40])
    if metadata.get('notes'): notes.append('Dataset caveat: '+str(metadata['notes']))
    claims = {}; plots = []; diagnostics = {}; excluded = 0
    def emit(label, metric, value, unit='', low=None, high=None, p=None):
        idx = len(out); values = {'estimate': value, 'lower': low, 'upper': high, 'p': p}
        row = dict(label=str(label), metric=metric, unit=unit)
        for key, val in values.items():
            val = float(val) if val is not None else None
            require(val is None or math.isfinite(val), f'{metric}: non-finite result; inference is not reportable')
            row[key] = val
            if val is not None:
                field=f'r{idx}_{key}'
                display = '<0.001' if key=='p' and val<.001 else str(int(val)) if metric in ['n','missing','numerator','denominator'] and val.is_integer() else fmt(val)
                claims[field] = {'value': val, 'display': display, 'label': f'{label}: {metric} {key}', 'unit': unit}
        out.append(row)
        return row
    claims['confidence']={'value': c*100, 'display':f'{c*100:g}%', 'label':'confidence level', 'unit':'percent'}
    def plot(row):
        if row['estimate'] is not None: plots.append(row)
    infer = method not in ['describe','proportion','rate','time']
    if infer:
        require(design['population']=='sample' and (design['unit']=='individual' or (method=='paired' and design['unit']=='pair')), 'Inference requires individual sample observations. Census/area/aggregate data support description only; ecological inference is unavailable')
        require(design.get('independent') is True, 'Independent observational units must be established; weighted, clustered or repeated designs need a supported model')
        require(not design.get('weighted') and not design.get('clustered'), 'Survey-weighted/clustered inference is not supported')
        notes.append('Confidence intervals and p-values are model-based, conditional on the documented sampling/design assumptions; residual checks do not prove independence.')
    else:
        notes.append('Descriptive summary of the supplied population; no sampling p-value is inferred.')
    if design.get('weighted'): notes.append('No survey weights are applied: this is an unweighted description, not a weighted population estimate.')
    x=spec.get('x'); y=spec.get('y'); group=spec.get('group'); units=spec['unit']
    if method in ['describe','time']:
        require(x in frame, 'Choose a measurement column')
        if method=='time':
            require(group in frame and spec.get('periodDefinition'), 'Time summaries require a period column and consistent interval/definition statement')
            require(frame[group].notna().all(), 'Time periods must not be missing')
            require(pd.to_datetime(frame[group],errors='coerce').notna().all(), 'Time periods must be valid ISO dates or years')
            require(frame[group].dropna().map(lambda v: isinstance(v,str) and __import__('re').fullmatch(r'\d{4}(?:-\d{2}(?:-\d{2})?)?',v) is not None).all(), 'Use ISO year/month/date labels so period ordering is explicit')
            notes.append('Observed period summaries only; gaps are shown as supplied, with no interpolation or forecast. Changes do not imply a causal trend.')
        require(not group or group in frame, 'Grouping column not found')
        groups = frame.groupby(group, dropna=False, sort=True) if group else [('All observations',frame)]
        require(not group or frame[group].nunique(dropna=False)<=40, 'Use at most 40 groups per analysis')
        for label, block in groups:
            label='Missing group' if pd.isna(label) else str(label)
            v=numeric(block,[x])[x].to_numpy(); n=len(v); missing=len(block)-n; excluded+=missing
            emit(label,'n',n,'observations'); emit(label,'missing',missing,'observations')
            if not n: continue
            mean=emit(label,'mean',np.mean(v),units); plot(mean)
            emit(label,'sample SD',np.std(v,ddof=1) if n>1 else None,units)
            for name,quantile in [('minimum',0),('Q1',.25),('median',.5),('Q3',.75),('maximum',1)]: emit(label,name,np.quantile(v,quantile,method='linear'),units)
        if method=='time' and len(plots)>=2: emit('Last period minus first period','observed mean change',plots[-1]['estimate']-plots[0]['estimate'],units)
        notes.append('SD uses n−1; quartiles use linear interpolation (NumPy method=linear). A single observation has no sample SD.')
    elif method in ['proportion','rate']:
        require(x and y, 'Supply numerator and denominator columns')
        require(spec.get('denominatorDefinition'), 'Define the denominator/reference population')
        v=numeric(frame,[x,y]); excluded=len(frame)-len(v)
        require(len(v)>0, 'No complete numerator/denominator rows')
        a,b=v[x].to_numpy(),v[y].to_numpy()
        require(np.all(a>=0) and np.all(b>0), 'Numerators must be non-negative and denominators strictly positive')
        require(np.all(a==np.floor(a)), 'Event counts must be integers')
        require(design.get('disjoint') is True, 'Totals require disjoint numerator/denominator populations; overlapping rows cannot be pooled')
        num,den=float(a.sum()),float(b.sum()); scale=100 if method=='proportion' else spec.get('scale',100000)
        require(0<scale<=1000000, 'Invalid rate scale')
        low=high=None
        if method=='proportion':
            require('percent' in units.lower() or '%' in units, 'Proportion output units must be percent (0–100)')
            require(np.all(a<=b) and np.all(b==np.floor(b)), 'Proportions require integer totals and numerator ≤ denominator in every row')
            if design['population']=='sample':
                require(design.get('independent') and not design.get('weighted') and not design.get('clustered'), 'Wilson intervals require independent unweighted binomial observations')
                low,high=np.array(proportion_confint(num,den,alpha=alpha,method='wilson'))*scale
                notes.append('Wilson score interval for a binomial proportion; assumes the specified independent sampling design.')
        emit('All complete rows','numerator',num,'events');emit('All complete rows','denominator',den,spec['denominatorDefinition'])
        plot(emit('All complete rows',method,num/den*scale,units,low,high))
        notes.append(f'Calculated from summed counts, not an unweighted mean of row percentages; multiplier={scale:g}. No event-rate confidence interval is assumed.')
    elif method in ['welch','paired','mann_whitney']:
        require(x and y and x!=y, 'Choose two distinct numeric columns (wide format)')
        require(spec.get('contrast')=='y minus x', 'The reported contrast must explicitly be y minus x')
        if method=='paired':
            require(spec.get('pairId') in frame and frame[spec['pairId']].notna().all() and frame[spec['pairId']].is_unique, 'Paired test requires a nonmissing unique pair ID and one explicit pair per row')
            v=numeric(frame,[x,y]);a=v[x].to_numpy();b=v[y].to_numpy();excluded=len(frame)-len(v)
        else:
            a=numeric(frame,[x])[x].to_numpy();b=numeric(frame,[y])[y].to_numpy();excluded=2*len(frame)-len(a)-len(b)
            require(spec.get('independentGroups') is True, 'Confirm the two columns represent independent groups, not paired/repeated measurements')
        require(min(len(a),len(b))>=3, 'Each group needs at least three valid observations for this implementation')
        emit(x,'n',len(a),'observations'); emit(y,'n',len(b),'observations')
        if method=='mann_whitney':
            test=stats.mannwhitneyu(b,a,alternative='two-sided',method='asymptotic')
            effect=2*test.statistic/(len(a)*len(b))-1
            emit('y relative to x','rank-biserial correlation',effect,'rank correlation',p=test.pvalue)
            diagnostics['U']=float(test.statistic);notes.append('Two-sided asymptotic Mann–Whitney U with tie and continuity corrections; a distribution comparison, not automatically a median test. No rank-effect interval is implemented.')
        else:
            if method=='paired':
                diff=b-a;df=len(diff)-1;se=np.std(diff,ddof=1)/np.sqrt(len(diff));effect=np.mean(diff)
                test=stats.ttest_rel(b,a);diagnostics['differenceShapiroP']=float(stats.shapiro(diff).pvalue) if len(diff)<=5000 else None
                notes.append('Paired t-test assumes independent pairs and approximately normal differences; pair members are not independent.')
            else:
                va,vb=np.var(a,ddof=1)/len(a),np.var(b,ddof=1)/len(b);se=np.sqrt(va+vb);df=(va+vb)**2/(va**2/(len(a)-1)+vb**2/(len(b)-1));effect=np.mean(b)-np.mean(a);test=stats.ttest_ind(b,a,equal_var=False)
                diagnostics['leveneP']=float(stats.levene(a,b).pvalue);notes.append('Welch test allows unequal variances; inspect skew/outliers and the independent sampling design.')
            require(se>0, 'Zero-variance contrast: inferential test unavailable')
            margin=stats.t.ppf(1-alpha/2,df)*se
            plot(emit('y minus x','mean difference',effect,units,effect-margin,effect+margin,test.pvalue));diagnostics.update(t=float(test.statistic),df=float(df),standardError=float(se))
    elif method in ['pearson','spearman']:
        require(x and y and x!=y,'Choose two distinct numeric columns')
        v=numeric(frame,[x,y]);excluded=len(frame)-len(v);require(len(v)>=4,'Correlation needs at least four complete pairs')
        a,b=v[x],v[y];require(a.nunique()>1 and b.nunique()>1,'Constant variables do not define correlation')
        if method=='pearson':
            test=stats.pearsonr(a,b);ci=test.confidence_interval(confidence_level=c);low,high=ci.low,ci.high
            notes.append('Pearson r; Fisher-z confidence interval assumes a suitable bivariate distribution. Inspect the relationship for nonlinearity and influential outliers.')
        else:
            test=stats.spearmanr(a,b);low=high=None
            if len(v)<500:
                ra=stats.rankdata(a);rb=stats.rankdata(b);centered_b=rb-rb.mean();denom=np.sqrt(np.sum((ra-ra.mean())**2)*np.sum(centered_b**2))
                def permuted(z,axis=-1): return np.sum((z-ra.mean())*centered_b,axis=axis)/denom
                permutation=stats.permutation_test((ra,),permuted,permutation_type='pairings',vectorized=True,n_resamples=9999,batch=128,alternative='two-sided',rng=np.random.default_rng(spec.get('seed',0)))
                test=SimpleNamespace(statistic=test.statistic,pvalue=permutation.pvalue)
                diagnostics.update(permutationResamples=len(permutation.null_distribution),seed=spec.get('seed',0))
            notes.append('Spearman rank correlation with average ranks for ties; seeded pairing-permutation p-value below 500 pairs, asymptotic p-value otherwise. No confidence interval is implemented; inspect monotonicity and ties.')
        emit('Complete pairs','n',len(v),'pairs');plot(emit(f'{x} with {y}',method+' correlation',test.statistic,'correlation',low,high,test.pvalue))
        diagnostics['tiedPairs']=int(len(v)-len(v.drop_duplicates()))
    elif method in ['chi_square','fisher']:
        require(x in frame and y in frame and x!=y, 'Choose two categorical columns of individual observations')
        v=frame[[x,y]].dropna();excluded=len(frame)-len(v);table=pd.crosstab(v[x],v[y]);require(min(table.shape)>=2 and max(table.shape)<=20,'Need 2–20 categories per variable')
        chi,p,df,expected=stats.chi2_contingency(table,correction=False)
        diagnostics.update(observed=table.values.tolist(),expected=expected.tolist(),rowLabels=list(map(str,table.index)),columnLabels=list(map(str,table.columns)))
        if method=='fisher':
            require(table.shape==(2,2),'Fisher exact currently supports two-by-two tables only')
            from scipy.stats.contingency import odds_ratio
            odds=odds_ratio(table.values);ci=odds.confidence_interval(confidence_level=c)
            require(np.isfinite(odds.statistic) and np.isfinite(ci.low) and np.isfinite(ci.high),'Sparse table has an unbounded odds-ratio interval; report counts and use specialist methods')
            test=stats.fisher_exact(table.values,alternative='two-sided');plot(emit('First category versus second','conditional odds ratio',odds.statistic,'odds ratio',ci.low,ci.high,test.pvalue))
            notes.append('Two-sided Fisher exact p-value; conditional maximum-likelihood odds ratio and exact confidence interval. Category ordering is recorded in diagnostics.')
        else:
            require(np.min(expected)>=1 and np.mean(expected<5)<=.2,'Chi-square approximation is unsuitable: expected cells too small; use Fisher for a suitable 2×2 table')
            effect=np.sqrt(chi/(table.values.sum()*min(table.shape[0]-1,table.shape[1]-1)))
            emit('Association','Cramér V',effect,'association',p=p);diagnostics.update(chiSquare=float(chi),df=int(df));notes.append('Pearson chi-square without continuity correction; Cramér V is uncorrected. No effect-size interval is implemented.')
        emit('Complete observations','n',len(v),'observations')
    elif method in ['ols','logistic','poisson']:
        predictors=spec.get('predictors',[]);require(1<=len(predictors)<=10 and len(set(predictors))==len(predictors) and y not in predictors,'Choose 1–10 distinct numeric predictors, excluding outcome')
        cols=[y]+predictors+([spec['exposure']] if spec.get('exposure') else []);require(len(cols)==len(set(cols)),'Exposure must be separate from outcome and predictors')
        v=numeric(frame,cols);excluded=len(frame)-len(v);require(len(v)>len(predictors)+3,'Insufficient complete cases for this design matrix')
        X=sm.add_constant(v[predictors],has_constant='add');Y=v[y];require(np.linalg.matrix_rank(X)==X.shape[1],'Rank-deficient design: remove redundant/constant predictors')
        require(Y.nunique()>1,'Constant outcome cannot support this regression')
        if method=='ols':
            model=sm.OLS(Y,X).fit();require(np.sum(model.resid**2)>1e-20,'Exact fit has no useful inferential residual variance')
            diagnostics.update(rSquared=float(model.rsquared),conditionNumber=float(model.condition_number),residualSD=float(np.sqrt(model.mse_resid)))
            from statsmodels.stats.diagnostic import het_breuschpagan
            diagnostics['breuschPaganP']=float(het_breuschpagan(model.resid,X)[1]);notes.append('OLS includes an intercept and conventional homoscedastic standard errors. Check residual distribution, heteroscedasticity, leverage and linearity; diagnostics do not validate the design.')
            diagnostics['durbinWatson']=float(sm.stats.durbin_watson(model.resid))
            diagnostics['residualShapiroP']=float(stats.shapiro(model.resid).pvalue) if len(v)<=5000 else None
            diagnostics['maximumCooksDistance']=float(np.max(model.get_influence().cooks_distance[0]))
        else:
            if method=='logistic':
                require(set(Y.unique())=={0,1},'Binary logistic outcome must contain both zero and one')
                family=sm.families.Binomial();offset=None
            else:
                require((Y>=0).all() and (Y==np.floor(Y)).all(),'Poisson outcomes must be non-negative integer counts')
                family=sm.families.Poisson();offset=None
                if spec.get('exposure'):
                    require((v[spec['exposure']]>0).all(),'Exposure must be positive');offset=np.log(v[spec['exposure']])
            with warnings.catch_warnings(record=True) as caught:
                warnings.simplefilter('always');model=sm.GLM(Y,X,family=family,offset=offset).fit(maxiter=100)
            require(model.converged and not any('separation' in str(w.message).lower() for w in caught),'Non-convergence or separation: coefficients are not reportable')
            require(np.max(np.abs(model.params))<30,'Extreme coefficients suggest separation/instability; specialist review required')
            diagnostics.update(converged=True,dispersion=float(model.pearson_chi2/model.df_resid),deviance=float(model.deviance))
            if method=='poisson' and diagnostics['dispersion']>1.5: raise Unsupported('Poisson overdispersion detected (>1.5): conventional intervals are not supported; use a suitable count model')
            notes.append('GLM includes an intercept; logistic reports odds ratios, Poisson incidence-rate ratios. Conditional associations only. Offset is log(exposure) when supplied.')
        ci=model.conf_int(alpha=alpha);adjusted=multipletests(model.pvalues,method='holm')[1] if spec.get('multiplicity')=='holm' else model.pvalues
        for i,name in enumerate(X.columns):
            estimate=float(model.params[name]);low,high=ci.loc[name]
            if method!='ols': estimate,low,high=np.exp([estimate,low,high])
            plot(emit(name,'coefficient' if method=='ols' else 'odds ratio' if method=='logistic' else 'incidence-rate ratio',estimate,(units if name=='const' else f'{units} per unit of {name}') if method=='ols' else 'ratio',low,high,np.asarray(adjusted)[i]))
        emit('Complete observations','n',len(v),'observations');diagnostics['residualDf']=float(model.df_resid)
        notes.append('P-values use Holm adjustment across all model coefficients, including intercept; confidence intervals are unadjusted.' if spec.get('multiplicity')=='holm' else 'P-values and confidence intervals are unadjusted; consider multiplicity across exploratory analyses.')
    else: raise Unsupported('Method is unavailable; no substitute calculation was performed')
    claims['inputRows']={'value':len(rows),'display':str(len(rows)),'label':'input rows','unit':'rows'}
    claims['excluded']={'value':excluded,'display':str(excluded),'label':'excluded observations or group cells','unit':'observations'}
    notes += ['Display uses three decimal places, with scientific notation for nonzero magnitudes below 0.001 or at least one billion; p-values below 0.001 are shown as <0.001.', f'Input rows: {len(rows)}. Excluded incomplete observations/cells: {excluded}.', f"Unit of observation: {design['unit']}; population: {design['population']}. {spec['designStatement']}", 'AI-assisted analysis output requires human scientific review; automated narrative is not a submission-ready WPSAR manuscript.']
    # Values displayed in narrative are the same fields as the tables and figures.
    narrative=[]
    for r in out:
        if r['metric'] not in ['n','missing','numerator','denominator']:
            phrase=f"{r['label']}: {r['metric']} = {fmt(r['estimate'])} {r['unit']}." if r['estimate'] is not None else f"{r['label']}: {r['metric']} unavailable."
            if r['lower'] is not None: phrase+=f" {c*100:g}% confidence interval {fmt(r['lower'])} to {fmt(r['upper'])}."
            if r['p'] is not None: phrase+=f" p {'<0.001' if r['p']<.001 else '= '+format(r['p'],'.3f')}."
            narrative.append(phrase)
    return {'kind':'statistical-result','engine':VERSION,'method':method,'title':spec['title'],'unit':units,'claims':claims,'rows':out,'plot':plots[:40],'diagnostics':diagnostics,'notes':notes,'narrative':narrative,'software':{'python':platform.python_version(),'numpy':np.__version__,'scipy':scipy.__version__,'pandas':pd.__version__,'statsmodels':statsmodels.__version__},'parameters':spec,'inputRows':len(rows),'excluded':excluded}

if __name__=='__main__':
    try:
        require(len(sys.argv)==3,'Expected manifest and output paths')
        with open(sys.argv[1],encoding='utf8') as f: manifest=json.load(f)
        with warnings.catch_warnings():
            warnings.simplefilter('error',RuntimeWarning)
            result=calculate(manifest)
        with open(sys.argv[2],'w',encoding='utf8') as f: json.dump(result,f,allow_nan=False,ensure_ascii=False)
    except Exception as e:
        with open(sys.argv[2],'w',encoding='utf8') as f: json.dump({'kind':'unsupported-statistical-analysis','error':str(e) if isinstance(e,Unsupported) else 'Calculation failed; check data validity, numerical stability and supported runtime versions.'},f)
