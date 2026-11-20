-- Separate survey-based official estimates from municipal SAE (different source domains and CI).
-- Observations and source metadata are loaded privately by the server operator, never in migrations.
CREATE TABLE public.agg_poverty_official (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  dataset_id bigint NOT NULL REFERENCES public.dim_dataset(dataset_id),
  geo_code text NOT NULL REFERENCES public.dim_geo(geo_code),
  geo_level public.geo_level_enum NOT NULL CHECK (geo_level IN ('national','region','province','citymun')),
  reference_year smallint NOT NULL CHECK (reference_year = 2023),
  poverty_incidence double precision,
  ci_low double precision,
  ci_high double precision,
  cv double precision,
  is_suppressed boolean NOT NULL DEFAULT false,
  value_status text NOT NULL CHECK (value_status IN ('available','unavailable','not_absolute_zero','suppressed')),
  source_label text NOT NULL CHECK (length(trim(source_label)) > 0),
  source_table text NOT NULL CHECK (source_table IN ('tab2','tab2a')),
  source_row integer NOT NULL CHECK (source_row > 6),
  boundary_note text NOT NULL CHECK (length(trim(boundary_note)) > 0),
  publication_note text NOT NULL CHECK (length(trim(publication_note)) > 0),
  workbook_sha256 text NOT NULL CHECK (workbook_sha256 ~ '^[a-f0-9]{64}$'),
  UNIQUE (dataset_id, geo_code, reference_year),
  CHECK (is_suppressed = (value_status = 'suppressed')),
  CHECK ((value_status = 'available' AND poverty_incidence BETWEEN 0 AND 100 AND poverty_incidence IS NOT NULL)
    OR (value_status <> 'available' AND poverty_incidence IS NULL)),
  CHECK (cv IS NULL OR (cv >= 0 AND cv < 'Infinity'::double precision)),
  CHECK (ci_low IS NULL OR (ci_low > '-Infinity'::double precision AND ci_low < 'Infinity'::double precision)),
  CHECK (ci_high IS NULL OR (ci_high > '-Infinity'::double precision AND ci_high < 'Infinity'::double precision)),
  CHECK (ci_low IS NULL OR ci_high IS NULL OR ci_low <= ci_high)
);
COMMENT ON TABLE public.agg_poverty_official IS 'PSA official full-year population poverty estimates, 2023 r2. Published 95% CI. Explicit source reporting geography; not SAE, not a rollup or a substitute for missing local values.';
ALTER TABLE public.agg_poverty_official ENABLE ROW LEVEL SECURITY;
CREATE POLICY official_poverty_public_read ON public.agg_poverty_official FOR SELECT TO anon, authenticated USING (true);
GRANT SELECT ON public.agg_poverty_official TO anon, authenticated;
