// A fixture for base-path-census.test.ts: one root-relative URL of each kind the census must find.
// Never imported by the app; the census skips every __fixtures__ directory when it reads the real tree.
export function Defects() {
  fetch("/api/feedback");
  window.location.assign("/districts/1");
  const url = new URL("/admin/login", "http://localhost");
  const geo = "/geo/regions.json";
  return (
    <a href="/methodology">
      {url.href}
      {geo}
    </a>
  );
}
