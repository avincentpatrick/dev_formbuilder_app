export default function CatalogLoading() {
  return (
    <div role="status" className="rounded-xl border border-border bg-surface p-6">
      <p className="font-medium">Loading the selected location and its datasets…</p>
      <p className="mt-2 text-sm text-muted">Checking availability, coverage and sources.</p>
    </div>
  );
}
