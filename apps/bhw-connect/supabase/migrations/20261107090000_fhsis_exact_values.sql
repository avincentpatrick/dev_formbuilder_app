-- Preserve fractional published values without changing legacy integer column types.
ALTER TABLE public.fact_fhsis_indicator ADD COLUMN numerator_exact numeric;
ALTER TABLE public.fact_fhsis_indicator ADD COLUMN denominator_exact numeric;
COMMENT ON COLUMN public.fact_fhsis_indicator.numerator_exact IS 'Published source value, including decimals. Prefer this to legacy numerator when present.';
COMMENT ON COLUMN public.fact_fhsis_indicator.denominator_exact IS 'Published denominator, including fractional projections. Prefer this to legacy denominator when present.';

CREATE VIEW public.ref_fhsis_reconciliation_exact WITH (security_invoker='true') AS
 WITH child_of AS (
         SELECT f.dataset_id,
            f.indicator_key,
            f.breakdown,
            COALESCE(f.numerator_exact, f.numerator::numeric) AS numerator,
            (
                CASE f.geo_level
                    WHEN 'citymun'::public.geo_level_enum THEN 'province'::text
                    WHEN 'province'::public.geo_level_enum THEN 'region'::text
                    WHEN 'region'::public.geo_level_enum THEN 'national'::text
                    ELSE NULL::text
                END)::public.geo_level_enum AS parent_level,
            g.parent_code
           FROM (public.fact_fhsis_indicator f
             JOIN public.dim_geo g ON ((g.geo_code = f.geo_code)))
          WHERE ((f.geo_level <> 'national'::public.geo_level_enum) AND (COALESCE(f.numerator_exact, f.numerator::numeric) IS NOT NULL))
        )
 SELECT c.dataset_id,
    c.indicator_key,
    c.breakdown,
    c.parent_level,
    c.parent_code AS parent_geo_code,
    p.geo_name AS parent_geo_name,
    (count(*))::integer AS child_count,
    sum(c.numerator) AS child_sum,
    COALESCE(parent.numerator_exact, parent.numerator::numeric) AS published_parent,
    (COALESCE(parent.numerator_exact, parent.numerator::numeric) - sum(c.numerator)) AS residual,
        CASE
            WHEN ((COALESCE(parent.numerator_exact, parent.numerator::numeric) - sum(c.numerator)) = 0) THEN 'reconciles'::text
            WHEN ((COALESCE(parent.numerator_exact, parent.numerator::numeric) - sum(c.numerator)) > 0) THEN 'leaves short of published parent'::text
            ELSE 'leaves exceed published parent'::text
        END AS finding
   FROM ((child_of c
     JOIN public.dim_geo p ON ((p.geo_code = c.parent_code)))
     JOIN public.fact_fhsis_indicator parent ON (((parent.dataset_id = c.dataset_id) AND (parent.geo_code = c.parent_code) AND (parent.indicator_key = c.indicator_key) AND (parent.breakdown = c.breakdown) AND (COALESCE(parent.numerator_exact, parent.numerator::numeric) IS NOT NULL))))
  GROUP BY c.dataset_id, c.indicator_key, c.breakdown, c.parent_level, c.parent_code, p.geo_name, parent.numerator_exact, parent.numerator;
GRANT SELECT ON public.ref_fhsis_reconciliation_exact TO anon, authenticated, service_role;
NOTIFY pgrst, 'reload schema';
